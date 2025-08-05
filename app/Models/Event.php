<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_template_id',
        'name',
        'client_name',
        'client_email',
        'client_phone',
        'start_date',
        'end_date',
        'duration_days',
        'transfer_km',
        'program_km',
        'bus_id',
        'markup_id',
        'participant_count',
        'total_cost',
        'status',
        'notes',
        'created_by',
        'assigned_to',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_cost' => 'decimal:2',
    ];

    protected static function booted()
    {
        static::creating(function ($event) {
            $event->created_by = Auth::id();
        });

        static::created(function ($event) {
            $event->logHistory('created', null, null, $event->toArray(), 'Impreza została utworzona');
        });

        static::updated(function ($event) {
            $changes = $event->getChanges();
            foreach ($changes as $field => $newValue) {
                $oldValue = $event->getOriginal($field);
                $event->logHistory('updated', $field, $oldValue, $newValue, "Zmieniono {$field}");
            }
        });
    }

    /**
     * Szablon imprezy
     */
    public function eventTemplate(): BelongsTo
    {
        return $this->belongsTo(EventTemplate::class);
    }

    /**
     * Twórca imprezy
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Przypisany użytkownik
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Punkty programu dla tej imprezy
     */
    public function programPoints(): HasMany
    {
        return $this->hasMany(EventProgramPoint::class);
    }

    /**
     * Historia zmian
     */
    public function history(): HasMany
    {
        return $this->hasMany(EventHistory::class);
    }

    /**
     * Snapshoty imprezy
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(EventSnapshot::class);
    }

    /**
     * Pierwotny snapshot imprezy
     */
    public function originalSnapshot(): HasOne
    {
        return $this->hasOne(EventSnapshot::class)->where('type', 'original');
    }

    /**
     * Utwórz imprezę na podstawie szablonu
     */
    public static function createFromTemplate(EventTemplate $template, array $data): self
    {
        // Oblicz duration_days na podstawie dat lub użyj z szablonu
        $durationDays = $template->duration_days ?? 1;
        if (isset($data['start_date']) && isset($data['end_date'])) {
            $startDate = \Carbon\Carbon::parse($data['start_date']);
            $endDate = \Carbon\Carbon::parse($data['end_date']);
            $durationDays = $startDate->diffInDays($endDate) + 1;
        }

        $event = self::create([
            'event_template_id' => $template->id,
            'name' => $data['name'],
            'client_name' => $data['client_name'],
            'client_email' => $data['client_email'] ?? null,
            'client_phone' => $data['client_phone'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'duration_days' => $durationDays,
            'transfer_km' => $template->transfer_km ?? 0,
            'program_km' => $template->program_km ?? 0,
            'bus_id' => $template->bus_id,
            'markup_id' => $template->markup_id,
            'participant_count' => $data['participant_count'] ?? 1,
            'assigned_to' => $data['assigned_to'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        // Skopiuj punkty programu z szablonu (w tym podpunkty)
        $event->copyProgramPointsFromTemplate();

        // Utwórz pierwotny snapshot imprezy
        EventSnapshot::createSnapshot(
            $event, 
            'original', 
            'Pierwotny stan imprezy',
            'Automatycznie utworzony snapshot w momencie tworzenia imprezy na podstawie szablonu: ' . $template->name
        );

        return $event;
    }

    /**
     * Skopiuj punkty programu z szablonu (w tym podpunkty)
     */
    public function copyProgramPointsFromTemplate(): void
    {
        $templatePoints = $this->eventTemplate->programPoints()
            ->with(['children', 'currency'])
            ->withPivot(['day', 'order', 'notes', 'include_in_program', 'include_in_calculation', 'active'])
            ->get();

        foreach ($templatePoints as $point) {
            // Skopiuj główny punkt programu
            $mainPoint = EventProgramPoint::create([
                'event_id' => $this->id,
                'event_template_program_point_id' => $point->id,
                'day' => $point->pivot->day,
                'order' => $point->pivot->order,
                'unit_price' => $this->convertToEventCurrency($point->unit_price ?? 0, $point->currency),
                'quantity' => 1,
                'total_price' => $this->convertToEventCurrency($point->unit_price ?? 0, $point->currency),
                'notes' => $point->pivot->notes,
                'include_in_program' => $point->pivot->include_in_program,
                'include_in_calculation' => $point->pivot->include_in_calculation,
                'active' => $point->pivot->active,
            ]);

            // Skopiuj podpunkty jako osobne punkty programu (rozwiń na jeden poziom)
            if ($point->children && $point->children->count() > 0) {
                $childOrder = $point->pivot->order + 0.1; // Suborder for children
                
                foreach ($point->children as $child) {
                    EventProgramPoint::create([
                        'event_id' => $this->id,
                        'event_template_program_point_id' => $child->id,
                        'day' => $point->pivot->day,
                        'order' => $childOrder,
                        'unit_price' => $this->convertToEventCurrency($child->unit_price ?? 0, $child->currency),
                        'quantity' => 1,
                        'total_price' => $this->convertToEventCurrency($child->unit_price ?? 0, $child->currency),
                        'notes' => "Podpunkt: {$child->name}",
                        'include_in_program' => $point->pivot->include_in_program,
                        'include_in_calculation' => $point->pivot->include_in_calculation,
                        'active' => $point->pivot->active,
                    ]);
                    
                    $childOrder += 0.1;
                }
            }
        }

        $this->logHistory('program_copied', null, null, null, 'Skopiowano punkty programu z szablonu (w tym podpunkty)');
        $this->calculateTotalCost();
    }

    /**
     * Konwertuj cenę do waluty imprezy (PLN)
     */
    private function convertToEventCurrency(float $price, $currency = null): float
    {
        if (!$currency || $currency->symbol === 'PLN') {
            return $price;
        }

        // Pobierz kurs waluty z tabeli currencies
        $exchangeRate = \App\Models\Currency::where('symbol', $currency->symbol)->first()?->exchange_rate ?? 1;
        
        return $price * $exchangeRate;
    }

    /**
     * Oblicz całkowity koszt imprezy
     */
    public function calculateTotalCost(): void
    {
        $totalCost = $this->programPoints()
            ->where('include_in_calculation', true)
            ->where('active', true)
            ->sum('total_price');

        $this->update(['total_cost' => $totalCost]);
    }

    /**
     * Zapisz historię zmian
     */
    public function logHistory(string $action, ?string $field = null, $oldValue = null, $newValue = null, ?string $description = null): void
    {
        EventHistory::create([
            'event_id' => $this->id,
            'user_id' => Auth::id() ?? 1, // Fallback do user ID 1 jeśli brak auth
            'action' => $action,
            'field' => $field,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'description' => $description,
            'ip_address' => request()->ip() ?? '127.0.0.1',
        ]);
    }

    /**
     * Sprawdź czy impreza może być edytowana
     */
    public function canBeEdited(): bool
    {
        return in_array($this->status, ['draft', 'confirmed']);
    }

    /**
     * Zmień status imprezy
     */
    public function changeStatus(string $newStatus, ?string $reason = null): void
    {
        $oldStatus = $this->status;
        
        // Utwórz snapshot przed zmianą statusu (dla ważnych statusów)
        if (in_array($newStatus, ['confirmed', 'completed', 'cancelled'])) {
            EventSnapshot::createSnapshot(
                $this,
                'status_change',
                "Snapshot przed zmianą na '{$newStatus}'",
                "Snapshot utworzony przed zmianą statusu z '{$oldStatus}' na '{$newStatus}'" . ($reason ? ". Powód: {$reason}" : '')
            );
        }
        
        $this->update(['status' => $newStatus]);
        
        $description = "Zmieniono status z '{$oldStatus}' na '{$newStatus}'";
        if ($reason) {
            $description .= ". Powód: {$reason}";
        }
        
        $this->logHistory('status_changed', 'status', $oldStatus, $newStatus, $description);
    }

    /**
     * Utwórz ręczny snapshot
     */
    public function createManualSnapshot(?string $name = null, ?string $description = null): EventSnapshot
    {
        return EventSnapshot::createSnapshot(
            $this,
            'manual',
            $name ?? 'Snapshot ręczny ' . now()->format('d.m.Y H:i'),
            $description ?? 'Ręcznie utworzony snapshot'
        );
    }

    /**
     * Przywróć do pierwotnego stanu
     */
    public function restoreToOriginal(): bool
    {
        $originalSnapshot = $this->originalSnapshot;
        
        if (!$originalSnapshot) {
            return false;
        }
        
        $originalSnapshot->restoreToEvent();
        return true;
    }

    /**
     * Porównaj z pierwotnym stanem
     */
    public function compareWithOriginal(): ?array
    {
        $originalSnapshot = $this->originalSnapshot;
        
        if (!$originalSnapshot) {
            return null;
        }
        
        return $originalSnapshot->compareWithCurrent();
    }

    /**
     * Autokar przypisany do imprezy
     */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * Markup przypisany do imprezy
     */
    public function markup(): BelongsTo
    {
        return $this->belongsTo(Markup::class);
    }
}
