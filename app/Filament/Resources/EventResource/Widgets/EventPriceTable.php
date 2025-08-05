<?php

namespace App\Filament\Resources\EventResource\Widgets;

use Filament\Widgets\Widget;
use App\Models\Event;

class EventPriceTable extends Widget
{
    protected static string $view = 'filament.resources.event-resource.widgets.event-price-table';
    public ?Event $record = null;
    protected int | string | array $columnSpan = 'full';
    
    public $calculations = [];
    public $programPoints;
    public $costsByDay;
    public $transportCost = 0;
    public $detailedCalculations = [];
    
    public function mount()
    {
        if ($this->record) {
            $this->loadCalculations();
        }
    }

    public function loadCalculations()
    {
        // Załaduj punkty programu z kosztami
        $this->programPoints = $this->record->programPoints()
            ->with('templatePoint')
            ->where('active', true)
            ->orderBy('day')
            ->orderBy('order')
            ->get();

        // Oblicz koszty transportu (podobnie jak w EventTemplate)
        $this->calculateTransportCost();

        // Oblicz koszty według dni
        $this->costsByDay = $this->programPoints
            ->groupBy('day')
            ->map(function ($points) {
                $totalCost = $points->where('include_in_calculation', true)->sum('total_price');
                $programCost = $points->where('include_in_program', true)->sum('total_price');
                
                return [
                    'points_count' => $points->count(),
                    'total_cost' => $totalCost,
                    'program_cost' => $programCost,
                    'calculation_points' => $points->where('include_in_calculation', true)->count(),
                    'program_points' => $points->where('include_in_program', true)->count(),
                    'points' => $points,
                ];
            });

        // Oblicz główne kalkulacje
        $totalProgramCost = $this->programPoints->where('include_in_calculation', true)->sum('total_price');
        $totalCostWithTransport = $totalProgramCost + $this->transportCost;

        $this->calculations = [
            'total_points' => $this->programPoints->count(),
            'active_points' => $this->programPoints->where('active', true)->count(),
            'calculation_points' => $this->programPoints->where('include_in_calculation', true)->count(),
            'program_points' => $this->programPoints->where('include_in_program', true)->count(),
            'total_program_cost' => $totalProgramCost,
            'transport_cost' => $this->transportCost,
            'total_cost' => $totalCostWithTransport,
            'program_cost' => $this->programPoints->where('include_in_program', true)->sum('total_price'),
            'cost_per_person' => $this->record->participant_count > 0 
                ? $totalCostWithTransport / $this->record->participant_count 
                : 0,
            'days_count' => $this->costsByDay->count(),
            'event_data' => [
                'name' => $this->record->name,
                'client_name' => $this->record->client_name,
                'participant_count' => $this->record->participant_count,
                'start_date' => $this->record->start_date,
                'end_date' => $this->record->end_date,
                'duration_days' => $this->record->duration_days,
                'transfer_km' => $this->record->transfer_km,
                'program_km' => $this->record->program_km,
                'status' => $this->record->status,
                'template_name' => $this->record->eventTemplate->name,
                'bus_name' => $this->record->bus?->name,
                'markup_name' => $this->record->markup?->name,
            ],
        ];

        // Oblicz szczegółowe kalkulacje z uwzględnieniem różnych wariantów
        $this->calculateDetailedPricing();
    }

    public function calculateTransportCost()
    {
        $this->transportCost = 0;
        
        if (!$this->record->bus) {
            return;
        }

        $bus = $this->record->bus;
        $transferKm = $this->record->transfer_km ?? 0;
        $programKm = $this->record->program_km ?? 0;
        $duration = $this->record->duration_days ?? 1;

        $totalKm = 2 * $transferKm + $programKm;
        $includedKm = $duration * ($bus->package_km_per_day ?? 0);
        $baseCost = $duration * ($bus->package_price_per_day ?? 0);

        if ($totalKm <= $includedKm) {
            $this->transportCost = $baseCost;
        } else {
            $extraKm = $totalKm - $includedKm;
            $this->transportCost = $baseCost + ($extraKm * ($bus->extra_km_price ?? 0));
        }

        // Przelicz na PLN jeśli autokar ma inną walutę
        if ($bus->currency && $bus->currency !== 'PLN') {
            // Znajdź walutę w tabeli currencies po symbolu
            $currency = \App\Models\Currency::where('symbol', $bus->currency)->first();
            $exchangeRate = $currency?->exchange_rate ?? 1;
            $this->transportCost *= $exchangeRate;
        }
    }

    public function calculateDetailedPricing()
    {
        // Symulacja różnych wariantów uczestników (jak w EventTemplate)
        $variants = [
            ['qty' => 10, 'name' => '10 osób'],
            ['qty' => 20, 'name' => '20 osób'],
            ['qty' => 30, 'name' => '30 osób'],
            ['qty' => 40, 'name' => '40 osób'],
            ['qty' => 50, 'name' => '50 osób'],
        ];

        $this->detailedCalculations = [];

        foreach ($variants as $variant) {
            $qty = $variant['qty'];
            $totalProgramCost = 0;

            // Oblicz koszt programu dla danej liczby osób
            foreach ($this->programPoints->where('include_in_calculation', true) as $point) {
                $groupSize = $point->templatePoint->group_size ?? 1;
                $unitPrice = $point->unit_price ?? 0;
                
                // Kalkulacja podobna do EventTemplate
                if ($groupSize <= 1) {
                    $cost = $qty * $unitPrice;
                } else {
                    $groupsNeeded = ceil($qty / $groupSize);
                    $cost = $groupsNeeded * $unitPrice;
                }
                
                $totalProgramCost += $cost;
            }

            // Oblicz koszt transportu dla danej liczby osób
            $transportCostForQty = $this->transportCost;
            if ($this->record->bus && $this->record->bus->capacity > 0) {
                $busesNeeded = ceil($qty / $this->record->bus->capacity);
                $transportCostForQty = $this->transportCost * $busesNeeded;
            }

            $totalCostForQty = $totalProgramCost + $transportCostForQty;

            $this->detailedCalculations[$qty] = [
                'qty' => $qty,
                'name' => $variant['name'],
                'program_cost' => $totalProgramCost,
                'transport_cost' => $transportCostForQty,
                'total_cost' => $totalCostForQty,
                'cost_per_person' => $totalCostForQty / $qty,
            ];
        }
    }

    public function refreshCalculations()
    {
        $this->record->refresh();
        $this->record->calculateTotalCost();
        $this->loadCalculations();
    }
}
