<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model EventTemplate
 * Reprezentuje szablon wydarzenia w systemie.
 *
 * @property int $id
 * @property string $name
 * @property string|null $subtitle
 * @property string $slug
 * @property int $duration_days
 * @property bool $is_active
 * @property string|null $featured_image
 * @property string|null $event_description
 * @property array|null $gallery
 * @property string|null $office_description
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class EventTemplate extends Model
{
    use HasFactory, SoftDeletes;

    public function transportTypes()
    {
        return $this->belongsToMany(TransportType::class);
    }

    public function eventTypes()
    {
        return $this->belongsToMany(EventType::class);
    }

    public function startPlace()
    {
        return $this->belongsTo(Place::class, 'start_place_id');
    }

    public function endPlace()
    {
        return $this->belongsTo(Place::class, 'end_place_id');
    }

    // Relacja: jeden event_template może mieć jeden event_price_description (pivot, nullable)
    public function eventPriceDescription()
    {
        return $this->belongsToMany(
            \App\Models\EventPriceDescription::class,
            'event_template_event_price_description',
            'event_template_id',
            'event_price_description_id'
        );
    }
    use HasFactory, SoftDeletes;

    /**
     * Pola masowo przypisywalne
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'subtitle',
        'slug',
        'duration_days',
        'is_active',
        'featured_image',
        'event_description',
        'gallery',
        'office_description',
        'notes',
        'transfer_km',
        'program_km',
        'bus_id',
        'transport_notes',
        'markup_id', // dodajemy pole do przypisania narzutu
        'start_place_id',
        'end_place_id',
        'show_title_style',
        'show_description',
    ];

    /**
     * Rzutowanie pól na typy
     * @var array<string, string>
     */
    protected $casts = [
        'gallery' => 'array',
        'is_active' => 'boolean',
        'show_title_style' => 'boolean',
        'show_description' => 'boolean',
    ];

    /**
     * Relacja wiele-do-wielu z tagami
     */
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'event_template_tag');
    }

    /**
     * Relacja wiele-do-wielu z punktami programu (tymczasowa implementacja)
     */
    public function programPoints()
    {
        return $this->belongsToMany(\App\Models\EventTemplateProgramPoint::class, 'event_template_event_template_program_point')
            ->withPivot([
                'id',
                'day',
                'order',
                'notes',
                'include_in_program',
                'include_in_calculation',
                'active',
                'show_title_style',
                'show_description',
            ]);
    }

    /**
     * Relacja wiele-do-wielu z podpunktami programu (pivot z właściwościami)
     */
    public function programPointChildren()
    {
        return $this->belongsToMany(
            \App\Models\EventTemplateProgramPoint::class,
            'event_template_program_point_child_pivot',
            'event_template_id',
            'program_point_child_id'
        )
            ->withPivot([
                'id',
                'include_in_program',
                'include_in_calculation',
                'active',
                'created_at',
                'updated_at',
            ]);
    }

    /**
     * Relacja jeden-do-wielu z wariantami ilości uczestników
     */
    public function qtyVariants()
    {
        return $this->hasMany(EventTemplateQty::class);
    }

    /**
     * Relacja jeden-do-wielu z cenami za osobę
     */
    public function pricesPerPerson()
    {
        return $this->hasMany(EventTemplatePricePerPerson::class);
    }

    /**
     * Ubezpieczenie przypisane do każdego dnia (event_template_day_insurance)
     */
    public function dayInsurances()
    {
        return $this->hasMany(\App\Models\EventTemplateDayInsurance::class);
    }

    /**
     * Pobierz ubezpieczenie dla danego dnia (lub null)
     */
    public function getInsuranceForDay($day)
    {
        return $this->dayInsurances()->where('day', $day)->first()?->insurance;
    }

    /**
     * Relacja wiele-do-jednego z tabelą bus
     */
    public function bus()
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * Relacja dni hotelowych (noclegów) dla eventu
     */
    public function hotelDays()
    {
        return $this->hasMany(EventTemplateHotelDay::class);
    }

    /**
     * Relacja wiele-do-jednego z tabelą markup
     */
    public function markup()
    {
        return $this->belongsTo(Markup::class);
    }

    /**
     * Relacja jeden-do-wielu z imprezami utworzonymi z tego szablonu
     */
    public function events()
    {
        return $this->hasMany(Event::class);
    }

    /**
     * Scope dla aktywnych szablonów
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope dla nieaktywnych szablonów
     */
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    /**
     * Relacja jeden-do-wielu z dostępnością miejsc startowych
     */
    public function startingPlaceAvailabilities()
    {
        return $this->hasMany(\App\Models\EventTemplateStartingPlaceAvailability::class);
    }

    /**
     * Relacja wiele-do-wielu z podatkami
     */
    public function taxes()
    {
        return $this->belongsToMany(Tax::class, 'event_template_tax');
    }
}
