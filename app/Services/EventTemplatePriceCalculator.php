<?php

namespace App\Services;

use App\Models\EventTemplate;
use App\Models\EventTemplateQty;
use App\Models\EventTemplatePricePerPerson;
use App\Models\Currency;
use Illuminate\Support\Facades\DB;

class EventTemplatePriceCalculator
{
    public function calculateAndSave(EventTemplate $eventTemplate): void
    {
        // Najpierw usuń nieaktualne rekordy cen
        $this->cleanupObsoleteRecords($eventTemplate);

        // Załaduj relacje potrzebne do kalkulacji
        $eventTemplate->load(['taxes', 'markup']);

        $programPoints = $eventTemplate->programPoints()
            ->with(['currency', 'children.currency'])
            ->wherePivot('include_in_calculation', true)
            ->get();

        $qtyVariants = \App\Models\EventTemplateQty::all();
        $currencies = collect();
        foreach ($programPoints as $point) {
            if ($point->currency) {
                $currencies->push($point->currency);
            }
            foreach ($point->children as $child) {
                if ($child->currency) {
                    $currencies->push($child->currency);
                }
            }
        }
        $currencies = $currencies->unique('id');

        // Pobierz dostępne miejsca startowe dla tego szablonu
        $availableStartPlaces = \App\Models\EventTemplateStartingPlaceAvailability::where('event_template_id', $eventTemplate->id)
            ->where('available', true)
            ->with('startPlace')
            ->get();

        // Jeśli nie ma dostępnych miejsc startowych, utwórz jeden rekord bez miejsca startowego (backward compatibility)
        if ($availableStartPlaces->isEmpty()) {
            $this->calculatePricesForStartPlace($eventTemplate, null, $qtyVariants, $currencies, $programPoints);
        } else {
            // Oblicz ceny dla każdego dostępnego miejsca startowego
            foreach ($availableStartPlaces as $availability) {
                $this->calculatePricesForStartPlace($eventTemplate, $availability->start_place_id, $qtyVariants, $currencies, $programPoints);
            }
        }
    }

    private function calculatePricesForStartPlace($eventTemplate, $startPlaceId, $qtyVariants, $currencies, $programPoints): void
    {
        // Pobierz podatki przypisane do tego szablonu wydarzenia
        $eventTaxes = $eventTemplate->taxes;

        // Oblicz koszt transportu dla tego miejsca startowego
        $transportCostPLN = $this->calculateTransportCost($eventTemplate, $startPlaceId);

        foreach ($qtyVariants as $qtyVariant) {
            $qty = $qtyVariant->qty;
            $qtyTotal = $qty + ($qtyVariant->gratis ?? 0) + ($qtyVariant->staff ?? 0) + ($qtyVariant->driver ?? 0);
            foreach ($currencies as $currency) {
                $total = 0;
                // Główne punkty
                foreach ($programPoints->where('currency_id', $currency->id) as $point) {
                    $groupSize = $point->group_size ?? 1;
                    $unitPrice = $point->unit_price ?? 0;
                    $pointPrice = ceil($qtyTotal / $groupSize) * $unitPrice;
                    $total += $pointPrice;
                }
                // Podpunkty
                foreach ($programPoints as $point) {
                    foreach ($point->children->where('currency_id', $currency->id) as $child) {
                        $groupSize = $child->group_size ?? 1;
                        $unitPrice = $child->unit_price ?? 0;
                        $childPrice = ceil($qtyTotal / $groupSize) * $unitPrice;
                        $total += $childPrice;
                    }
                }

                // Dodaj koszt transportu tylko dla PLN (zabezpieczone sprawdzanie waluty)
                $transportPerPerson = 0;
                if ($this->isPolishCurrency($currency) && $transportCostPLN > 0) {
                    $transportPerPerson = ceil($transportCostPLN / $qtyTotal);
                    $total += $transportCostPLN;
                    \Illuminate\Support\Facades\Log::info("Adding transport cost: transportCostPLN={$transportCostPLN}, qtyTotal={$qtyTotal}, transportPerPerson={$transportPerPerson}, new total={$total}, currency={$currency->name}");
                } else {
                    \Illuminate\Support\Facades\Log::info("No transport cost added: currency={$currency->name} (code: {$currency->code}), transportCostPLN={$transportCostPLN}, qtyTotal={$qtyTotal}");
                }

                // Oblicz narzut (markup)
                $markupAmount = 0;
                if ($eventTemplate->markup && $eventTemplate->markup->percent > 0) {
                    $markupAmount = ($total * $eventTemplate->markup->percent) / 100;
                }

                // Suma bez podatków
                $priceWithoutTax = $total + $markupAmount;

                // Oblicz podatki
                $taxBreakdown = [];
                $totalTaxAmount = 0;
                foreach ($eventTaxes as $tax) {
                    if (!$tax->is_active) continue;
                    $taxAmount = $tax->calculateTaxAmount($total, $markupAmount);
                    if ($taxAmount > 0) {
                        $taxBreakdown[] = [
                            'tax_id' => $tax->id,
                            'tax_name' => $tax->name,
                            'tax_percentage' => $tax->percentage,
                            'apply_to_base' => $tax->apply_to_base,
                            'apply_to_markup' => $tax->apply_to_markup,
                            'tax_amount' => round($taxAmount, 2)
                        ];
                        $totalTaxAmount += $taxAmount;
                    }
                }

                // Cena końcowa z podatkami
                $priceWithTax = $priceWithoutTax + $totalTaxAmount;

                // Cena za osobę: dziel tylko przez uczestników (qty)
                $pricePerPerson = $qty > 0 ? round($priceWithTax / $qty, 2) : 0;

                $saveData = [
                    'price_per_person' => $pricePerPerson, // Cena za osobę z podatkami
                    'price_per_tax' => round($totalTaxAmount, 2),  // Kwota podatków za osobę
                    'transport_cost' => $this->isPolishCurrency($currency) ? round($transportCostPLN, 2) : null,
                    'price_base' => round($total, 2),
                    'markup_amount' => round($markupAmount, 2),
                    'tax_amount' => round($totalTaxAmount, 2),
                    'price_with_tax' => round($priceWithTax, 2),
                    'tax_breakdown' => $taxBreakdown,
                    'updated_at' => now(),
                ];
                \Illuminate\Support\Facades\Log::info("Saving price data: " . json_encode($saveData) . " for startPlace={$startPlaceId}, qty={$qty}, currency={$currency->code}");
                EventTemplatePricePerPerson::updateOrCreate([
                    'event_template_id' => $eventTemplate->id,
                    'event_template_qty_id' => $qtyVariant->id,
                    'currency_id' => $currency->id,
                    'start_place_id' => $startPlaceId,
                ], $saveData);
            }
        }
    }

    private function calculateTransportCost($eventTemplate, $startPlaceId): float
    {
        if (!$startPlaceId || !$eventTemplate->start_place_id || !$eventTemplate->end_place_id) {
            \Illuminate\Support\Facades\Log::info("Transport cost = 0: Missing places. startPlaceId={$startPlaceId}, template_start={$eventTemplate->start_place_id}, template_end={$eventTemplate->end_place_id}");
            return 0;
        }

        // Odległość: miejsce startowe → początek programu
        $d1 = \App\Models\PlaceDistance::where('from_place_id', $startPlaceId)
            ->where('to_place_id', $eventTemplate->start_place_id)
            ->first()?->distance_km ?? 0;

        // Odległość: koniec programu → miejsce startowe  
        $d2 = \App\Models\PlaceDistance::where('from_place_id', $eventTemplate->end_place_id)
            ->where('to_place_id', $startPlaceId)
            ->first()?->distance_km ?? 0;

        // Program km
        $programKm = $eventTemplate->program_km ?? 0;

        $basicDistance = $d1 + $d2 + $programKm;

        // Wzór: 1.1 × podstawowa_odległość + 50 km
        $transportCost = 1.1 * $basicDistance + 50;

        \Illuminate\Support\Facades\Log::info("Transport cost calculation: d1={$d1}, d2={$d2}, programKm={$programKm}, basicDistance={$basicDistance}, transportCost={$transportCost} for startPlace={$startPlaceId}, template={$eventTemplate->id}");

        return $transportCost;
    }

    /**
     * Usuwa nieaktualne rekordy cen dla szablonu
     */
    private function cleanupObsoleteRecords(EventTemplate $eventTemplate): void
    {
        // Pobierz aktualne ID wariantów ilości uczestników
        $currentQtyIds = \App\Models\EventTemplateQty::pluck('id')->toArray();

        // Pobierz aktualne ID dostępnych miejsc startowych dla tego szablonu
        $availableStartPlaceIds = \App\Models\EventTemplateStartingPlaceAvailability::where('event_template_id', $eventTemplate->id)
            ->where('available', true)
            ->pluck('start_place_id')
            ->toArray();

        // Jeśli nie ma dostępnych miejsc startowych, zachowaj rekordy z start_place_id = null (backward compatibility)
        if (empty($availableStartPlaceIds)) {
            $availableStartPlaceIds = [null];
        }

        // Usuń rekordy dla nieistniejących wariantów ilości uczestników
        EventTemplatePricePerPerson::where('event_template_id', $eventTemplate->id)
            ->whereNotIn('event_template_qty_id', $currentQtyIds)
            ->delete();

        // Usuń rekordy dla niedostępnych miejsc startowych
        $query = EventTemplatePricePerPerson::where('event_template_id', $eventTemplate->id);

        if (in_array(null, $availableStartPlaceIds)) {
            // Jeśli null jest dozwolone, usuń tylko te które mają start_place_id nie na liście (ale nie null)
            $availableStartPlaceIdsWithoutNull = array_filter($availableStartPlaceIds, fn($id) => $id !== null);
            if (!empty($availableStartPlaceIdsWithoutNull)) {
                $query->where(function ($q) use ($availableStartPlaceIdsWithoutNull) {
                    $q->whereNotNull('start_place_id')
                        ->whereNotIn('start_place_id', $availableStartPlaceIdsWithoutNull);
                });
            } else {
                // Usuń wszystkie z start_place_id != null jeśli tylko null jest dozwolone
                $query->whereNotNull('start_place_id');
            }
        } else {
            // Usuń rekordy z start_place_id = null oraz te nie na liście
            $query->where(function ($q) use ($availableStartPlaceIds) {
                $q->whereNull('start_place_id')
                    ->orWhereNotIn('start_place_id', $availableStartPlaceIds);
            });
        }

        $query->delete();
    }

    /**
     * Sprawdza czy waluta to polski złoty (zabezpieczone przed duplikatami)
     */
    private function isPolishCurrency($currency): bool
    {
        if (!$currency) {
            return false;
        }

        // Sprawdź kod waluty (jeśli jest ustawiony)
        if (!empty($currency->code) && $currency->code === 'PLN') {
            return true;
        }

        // Sprawdź nazwę waluty (zabezpieczone przed różnymi wariantami)
        $name = strtolower($currency->name ?? '');

        return str_contains($name, 'polski') && str_contains($name, 'złoty') ||
            str_contains($name, 'złoty') && str_contains($name, 'polski') ||
            $name === 'pln' ||
            $name === 'polski złoty' ||
            $name === 'złoty polski';
    }
}
