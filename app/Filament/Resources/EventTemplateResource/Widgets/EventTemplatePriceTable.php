<?php

namespace App\Filament\Resources\EventTemplateResource\Widgets;

use Filament\Widgets\Widget;
use App\Models\EventTemplate;
use App\Models\EventTemplatePricePerPerson;
use App\Services\EventTemplatePriceCalculator;
use Filament\Notifications\Notification;

class EventTemplatePriceTable extends Widget
{
    /**
     * Zaokrągla wartość w górę do najbliższych 5 zł
     */
    private function ceilTo5($value): float
    {
        return ceil($value / 5) * 5;
    }
    protected static string $view = 'filament.resources.event-template-resource.widgets.event-template-price-table';
    public ?EventTemplate $record = null;
    public ?\App\Models\Place $startPlace = null;
    public ?int $startPlaceId = null;
    public ?float $transportKm = null;
    protected int | string | array $columnSpan = 'full';

    public $prices = [];
    public $detailedCalculations = [];
    public $qtyVariants = [];

    public function mount()
    {
        // Pobierz start_place_id z parametru URL lub z właściwości
        if (!$this->startPlaceId) {
            $this->startPlaceId = request()->get('start_place');
        }

        // Wczytaj start place jeśli jest ustawiony
        if ($this->startPlaceId) {
            $this->startPlace = \App\Models\Place::find($this->startPlaceId);
        }

        // Dodaj debugging
        \Illuminate\Support\Facades\Log::info("EventTemplatePriceTable mount - startPlaceId: " . ($this->startPlaceId ?? 'NULL') . ", startPlace: " . ($this->startPlace ? $this->startPlace->name : 'NULL'));

        // Wczytaj markup i podatki wraz z rekordem
        if ($this->record) {
            $this->record->load(['markup', 'taxes']);
        }
        $this->prices = $this->getPricesProperty();
        $this->qtyVariants = $this->getQtyVariantsProperty();
        $this->detailedCalculations = $this->getDetailedCalculations();

        // Dodaj dodatkowe debugging
        \Illuminate\Support\Facades\Log::info("EventTemplatePriceTable mount - prices count: " . (is_array($this->prices) ? count($this->prices) : $this->prices->count()));
    }

    public function getPricesProperty()
    {
        if (!$this->record) return collect();

        // Znajdź wszystkie polskie waluty (może być duplikatów)
        $polishCurrencyIds = \App\Models\Currency::where(function ($q) {
            $q->where('name', 'like', '%polski%złoty%')
                ->orWhere('name', 'like', '%złoty%polski%')
                ->orWhere('name', '=', 'Polski złoty')
                ->orWhere('name', '=', 'Złoty polski')
                ->orWhere('code', '=', 'PLN');
        })->pluck('id')->toArray();

        $query = EventTemplatePricePerPerson::with(['eventTemplateQty', 'currency', 'startPlace'])
            ->where('event_template_id', $this->record->id)
            ->whereIn('currency_id', $polishCurrencyIds); // Tylko polskie waluty

        // Jeśli jest wybrany start place, filtruj według niego
        if ($this->startPlaceId) {
            $query->where('start_place_id', $this->startPlaceId);
        } else {
            // Jeśli nie ma start place, pokazuj ceny bez miejsca startowego (backward compatibility)
            $query->whereNull('start_place_id');
        }

        $allPrices = $query->orderBy('event_template_qty_id')
            ->orderBy('currency_id')
            ->get();

        // Grupuj po qty i sumuj ceny z różnych walut PLN (tak jak w EventTemplateTransport::getPricesData())
        $groupedPrices = $allPrices->groupBy('event_template_qty_id');

        $results = collect();
        foreach ($groupedPrices as $qtyId => $pricesForQty) {
            // Sumuj wszystkie ceny dla tej samej ilości uczestników (z różnych walut PLN)
            $totalPriceBase = $pricesForQty->sum('price_base') ?: 0;
            $totalMarkup = $pricesForQty->sum('markup_amount') ?: 0;
            $totalTax = $pricesForQty->sum('tax_amount') ?: 0;
            $totalPriceWithTax = $pricesForQty->sum('price_with_tax') ?: $pricesForQty->sum('price_per_person') ?: 0;
            $totalTransportCost = $pricesForQty->sum('transport_cost') ?: 0;

            // Weź qty i inne dane z pierwszego rekordu
            $firstPrice = $pricesForQty->first();

            // Znajdź najlepszą polską walutę dla tego rekordu
            $bestCurrency = $this->findBestPolishCurrency();

            // Utwórz kombinowany obiekt cenowy
            $combinedPrice = new \stdClass();
            $combinedPrice->id = $firstPrice->id;
            $combinedPrice->event_template_id = $firstPrice->event_template_id;
            $combinedPrice->event_template_qty_id = $qtyId;
            $combinedPrice->start_place_id = $firstPrice->start_place_id;
            $combinedPrice->currency_id = $bestCurrency ? $bestCurrency->id : $firstPrice->currency_id;
            $combinedPrice->price_base = $totalPriceBase;
            $combinedPrice->markup_amount = $totalMarkup;
            $combinedPrice->tax_amount = $totalTax;
            $combinedPrice->price_with_tax = $totalPriceWithTax;
            $combinedPrice->price_per_person = $totalPriceWithTax; // Alias
            $combinedPrice->transport_cost = $totalTransportCost;

            // Zachowaj relacje
            $combinedPrice->eventTemplateQty = $firstPrice->eventTemplateQty;
            $combinedPrice->currency = $bestCurrency ?: $firstPrice->currency;
            $combinedPrice->startPlace = $firstPrice->startPlace;

            // Dodaj podatki breakdown jeśli istnieją
            $taxBreakdown = [];
            foreach ($pricesForQty as $price) {
                if ($price->tax_breakdown && is_array($price->tax_breakdown)) {
                    foreach ($price->tax_breakdown as $tax) {
                        $taxName = $tax['tax_name'] ?? 'Nieznany podatek';
                        if (!isset($taxBreakdown[$taxName])) {
                            $taxBreakdown[$taxName] = 0;
                        }
                        $taxBreakdown[$taxName] += floatval($tax['tax_amount'] ?? 0);
                    }
                }
            }

            // Konwertuj breakdown z powrotem do formatu
            $combinedPrice->tax_breakdown = [];
            foreach ($taxBreakdown as $taxName => $taxAmount) {
                $combinedPrice->tax_breakdown[] = [
                    'tax_name' => $taxName,
                    'tax_amount' => $taxAmount
                ];
            }

            $results->push($combinedPrice);
        }

        return $results;
    }

    /**
     * Znajdź najlepszą polską walutę w systemie (zabezpieczone przed duplikatami)
     */
    private function findBestPolishCurrency(): ?\App\Models\Currency
    {
        // Najpierw znajdź polskie waluty, które faktycznie mają dane cenowe dla tego szablonu
        $polishCurrenciesWithData = \App\Models\Currency::where(function ($q) {
            $q->where('name', 'like', '%polski%złoty%')
                ->orWhere('name', 'like', '%złoty%polski%')
                ->orWhere('name', '=', 'Polski złoty')
                ->orWhere('name', '=', 'Złoty polski')
                ->orWhere('code', '=', 'PLN');
        })
            ->whereHas('eventTemplatePrices', function ($q) {
                $q->where('event_template_id', $this->record->id);
            })
            ->orderBy('id') // Preferuj najniższe ID
            ->get();

        // Jeśli są waluty z danymi, zwróć pierwszą
        if ($polishCurrenciesWithData->isNotEmpty()) {
            return $polishCurrenciesWithData->first();
        }

        // Fallback: zwróć pierwszą polską walutę (nawet bez danych)
        return \App\Models\Currency::where(function ($q) {
            $q->where('name', 'like', '%polski%złoty%')
                ->orWhere('name', 'like', '%złoty%polski%')
                ->orWhere('name', '=', 'Polski złoty')
                ->orWhere('name', '=', 'Złoty polski')
                ->orWhere('code', '=', 'PLN');
        })
            ->orderBy('id')
            ->first();
    }

    public function getQtyVariantsProperty()
    {
        // Zwraca tablicę wariantów qty z kluczem qty
        $variants = [];
        foreach (\App\Models\EventTemplateQty::all() as $variant) {
            $variants[$variant->qty] = [
                'qty' => $variant->qty,
                'gratis' => $variant->gratis ?? 0,
                'staff' => $variant->staff ?? 0,
                'driver' => $variant->driver ?? 0,
            ];
        }
        return $variants;
    }

    public function getDetailedCalculations()
    {
        if (!$this->record) return [];

        $programPoints = $this->record->programPoints()
            ->with(['currency', 'children.currency'])
            ->wherePivot('include_in_calculation', true)
            ->get();

        $qtyVariants = \App\Models\EventTemplateQty::all();
        $calculations = [];

        $bus = $this->record->bus;
        $programKm = $this->record->program_km ?? 0;
        $startPlaceId = $this->startPlaceId;
        $templateStartId = $this->record->start_place_id;
        $templateEndId = $this->record->end_place_id;

        // Pobierz d1 i d2 z place_distances
        $d1 = 0;
        $d2 = 0;
        if ($startPlaceId && $templateStartId) {
            $d1 = \App\Models\PlaceDistance::where('from_place_id', $startPlaceId)
                ->where('to_place_id', $templateStartId)
                ->first()?->distance_km ?? 0;
        }
        if ($templateEndId && $startPlaceId) {
            $d2 = \App\Models\PlaceDistance::where('from_place_id', $templateEndId)
                ->where('to_place_id', $startPlaceId)
                ->first()?->distance_km ?? 0;
        }

        $basicDistance = $d1 + $d2 + $programKm;
        if ($this->transportKm !== null) {
            $totalKm = $this->transportKm;
        } else {
            $totalKm = 1.1 * $basicDistance + 50;
        }

        $duration = $this->record->duration_days ?? 1;
        $busTransportCost = null;
        $busCurrency = null;
        if ($bus) {
            $includedKm = $duration * $bus->package_km_per_day;
            $baseCost = $duration * $bus->package_price_per_day;
            $busCurrency = $bus->currency ?? 'PLN';
            if ($totalKm <= $includedKm) {
                $busTransportCost = $baseCost;
            } else {
                $extraKm = $totalKm - $includedKm;
                $busTransportCost = $baseCost + ($extraKm * $bus->extra_km_price);
            }
        }

        foreach ($qtyVariants as $qtyVariant) {
            $qty = $qtyVariant->qty;
            $qtyTotal = $qty + ($qtyVariant->gratis ?? 0) + ($qtyVariant->staff ?? 0) + ($qtyVariant->driver ?? 0);
            $busMultiplier = 1;
            if ($bus && $bus->capacity > 0 && $qtyTotal > $bus->capacity) {
                $busMultiplier = (int) ceil($qtyTotal / $bus->capacity);
            }
            $calculations[$qty] = [];
            $plnTotal = 0;
            $plnPoints = [];
            $currenciesTotals = [];
            $currenciesPoints = [];
            $hotelStructure = [];
            $hotelTotal = [];

            foreach ($programPoints as $point) {
                if ($point->currency) {
                    $currencyCode = $point->currency->symbol;
                    $currencySymbol = $point->currency->symbol ?? $currencyCode;
                    $exchangeRate = $point->currency->exchange_rate ?? 1;
                    $groupSize = $point->group_size ?? 1;
                    $unitPrice = $point->unit_price ?? 0;
                    // Poprawka: koszt liczony tylko dla uczestników (qty)
                    $cost = $this->calculatePointCost($qty, $groupSize, $unitPrice);
                    $convertToPln = $point->convert_to_pln ?? false;

                    if ($currencyCode === 'PLN') {
                        $plnPoints[] = [
                            'name' => $point->name,
                            'unit_price' => $unitPrice,
                            'group_size' => $groupSize,
                            'cost' => $cost,
                            'is_child' => false,
                            'currency_symbol' => $currencySymbol
                        ];
                        $plnTotal += $cost;
                    } elseif ($convertToPln) {
                        $plnPoints[] = [
                            'name' => $point->name . ' (przeliczone na PLN, kurs: ' . $exchangeRate . ')',
                            'unit_price' => $unitPrice . ' ' . $currencySymbol,
                            'group_size' => $groupSize,
                            'cost' => $cost * $exchangeRate,
                            'is_child' => false,
                            'currency_symbol' => 'PLN',
                            'original_currency' => $currencySymbol,
                            'exchange_rate' => $exchangeRate
                        ];
                        $plnTotal += $cost * $exchangeRate;
                    } else {
                        $currenciesPoints[$currencyCode][] = [
                            'name' => $point->name,
                            'unit_price' => $unitPrice,
                            'group_size' => $groupSize,
                            'cost' => $cost,
                            'is_child' => false,
                            'currency_symbol' => $currencySymbol
                        ];
                        $currenciesTotals[$currencyCode] = ($currenciesTotals[$currencyCode] ?? 0) + $cost;
                    }

                    // Podpunkty
                    foreach ($point->children as $child) {
                        if ($child->currency) {
                            $childCurrencyCode = $child->currency->symbol;
                            $childCurrencySymbol = $child->currency->symbol ?? $childCurrencyCode;
                            $childExchangeRate = $child->currency->exchange_rate ?? 1;
                            $childGroupSize = $child->group_size ?? 1;
                            $childUnitPrice = $child->unit_price ?? 0;
                            $childCost = $this->calculatePointCost($qty, $childGroupSize, $childUnitPrice);
                            $childConvertToPln = $child->convert_to_pln ?? false;

                            if ($childCurrencyCode === 'PLN') {
                                $plnPoints[] = [
                                    'name' => '→ ' . $child->name,
                                    'unit_price' => $childUnitPrice,
                                    'group_size' => $childGroupSize,
                                    'cost' => $childCost,
                                    'is_child' => true,
                                    'currency_symbol' => $childCurrencySymbol
                                ];
                                $plnTotal += $childCost;
                            } elseif ($childConvertToPln) {
                                $plnPoints[] = [
                                    'name' => '→ ' . $child->name . ' (przeliczone na PLN, kurs: ' . $childExchangeRate . ')',
                                    'unit_price' => $childUnitPrice . ' ' . $childCurrencySymbol,
                                    'group_size' => $childGroupSize,
                                    'cost' => $childCost * $childExchangeRate,
                                    'is_child' => true,
                                    'currency_symbol' => 'PLN',
                                    'original_currency' => $childCurrencySymbol,
                                    'exchange_rate' => $childExchangeRate
                                ];
                                $plnTotal += $childCost * $childExchangeRate;
                            } else {
                                $currenciesPoints[$childCurrencyCode][] = [
                                    'name' => '→ ' . $child->name,
                                    'unit_price' => $childUnitPrice,
                                    'group_size' => $childGroupSize,
                                    'cost' => $childCost,
                                    'is_child' => true,
                                    'currency_symbol' => $childCurrencySymbol
                                ];
                                $currenciesTotals[$childCurrencyCode] = ($currenciesTotals[$childCurrencyCode] ?? 0) + $childCost;
                            }
                        }
                    }
                }
            }

            // PLN na pierwszym miejscu
            // DODAJ KOSZT UBEZPIECZENIA DO PLN
            $insuranceTotal = 0;
            $insuranceNames = [];
            $dayInsurances = $this->record->dayInsurances ?? collect();
            foreach ($dayInsurances as $dayInsurance) {
                $insurance = $dayInsurance->insurance;
                if ($insurance && $insurance->insurance_enabled) {
                    $insuranceNames[] = $insurance->name;
                    if ($insurance->insurance_per_day) {
                        $insuranceTotal += $insurance->price_per_person * 1; // 1 dzień
                    }
                    if ($insurance->insurance_per_person) {
                        $insuranceTotal += $insurance->price_per_person * max(0, $qty - ($qtyVariant->gratis ?? 0));
                    }
                }
            }
            if ($insuranceTotal > 0) {
                $plnPoints[] = [
                    'name' => 'Ubezpieczenie' . (!empty($insuranceNames) ? ' (' . implode(', ', $insuranceNames) . ')' : ''),
                    'unit_price' => null,
                    'group_size' => null,
                    'cost' => $insuranceTotal,
                    'is_child' => false,
                    'currency_symbol' => 'PLN',
                ];
                $plnTotal += $insuranceTotal;
            }
            $calculations[$qty]['PLN'] = [
                'total' => $plnTotal,
                'points' => $plnPoints
            ];
            foreach ($currenciesTotals as $code => $total) {
                if ($code !== 'PLN') {
                    $calculations[$qty][$code] = [
                        'total' => $total,
                        'points' => $currenciesPoints[$code] ?? []
                    ];
                }
            }                // --- NOCLEGI ---
            $hotelDays = $this->record->hotelDays()->get();
            foreach ($hotelDays as $hotelDay) {
                $roomGroups = [
                    'qty' => [
                        'count' => $qty,
                        'room_ids' => $hotelDay->hotel_room_ids_qty ?? [],
                    ],
                    'gratis' => [
                        'count' => $qtyVariant->gratis ?? 0,
                        'room_ids' => $hotelDay->hotel_room_ids_gratis ?? [],
                    ],
                    'staff' => [
                        'count' => $qtyVariant->staff ?? 0,
                        'room_ids' => $hotelDay->hotel_room_ids_staff ?? [],
                    ],
                    'driver' => [
                        'count' => $qtyVariant->driver ?? 0,
                        'room_ids' => $hotelDay->hotel_room_ids_driver ?? [],
                    ],
                ];
                $dayTotal = [];
                $roomAlloc = [];
                foreach ($roomGroups as $groupType => $groupData) {
                    $peopleCount = $groupData['count'];
                    $roomIds = $groupData['room_ids'];
                    if ($peopleCount <= 0) continue;
                    if (empty($roomIds)) {
                        // Dodaj informację o braku pokoi dla tej grupy i noclegu
                        $roomAlloc[] = [
                            'room' => null,
                            'alloc' => null,
                            'total_people' => $peopleCount,
                            'cost' => 0,
                            'currency' => null,
                            'group_type' => $groupType,
                            'room_count' => 0,
                            'warning' => 'Brak przypisanych pokoi dla tej grupy (' . $groupType . ') w noclegu.'
                        ];
                        continue;
                    }
                    $rooms = \App\Models\HotelRoom::whereIn('id', $roomIds)->get();

                    $roomTypeCount = [];
                    foreach ($rooms as $room) {
                        $roomTypeCount[$room->id] = 0;
                    }

                    $maxPeople = $peopleCount;
                    $maxCapacity = $rooms->sum('people_count') * ($peopleCount); // duży zapas
                    $dp = array_fill(0, $maxCapacity + 1, INF);
                    $dp[0] = 0;
                    $choice = array_fill(0, $maxCapacity + 1, null);

                    foreach ($rooms as $room) {
                        for ($i = $room->people_count; $i <= $maxCapacity; $i++) {
                            if ($dp[$i - $room->people_count] + $room->price < $dp[$i]) {
                                $dp[$i] = $dp[$i - $room->people_count] + $room->price;
                                $choice[$i] = $room->id;
                            }
                        }
                    }

                    // Szukaj najtańszego rozwiązania dla liczby miejsc >= liczba osób
                    $minCost = INF;
                    $bestI = null;
                    for ($i = $peopleCount; $i <= $maxCapacity; $i++) {
                        if ($dp[$i] < $minCost) {
                            $minCost = $dp[$i];
                            $bestI = $i;
                        }
                    }

                    if ($minCost === INF) {
                        // Nie udało się przydzielić żadnej kombinacji
                        $roomAlloc[] = [
                            'room' => null,
                            'alloc' => null,
                            'total_people' => $peopleCount,
                            'cost' => 0,
                            'currency' => null,
                            'group_type' => $groupType,
                            'room_count' => 0,
                            'warning' => 'Brak możliwej kombinacji pokoi dla tej grupy (' . $groupType . ') w noclegu.'
                        ];
                    } else {
                        // Odtwarzanie wyboru pokoi
                        $allocRooms = [];
                        $i = $bestI;
                        while ($i > 0 && $choice[$i] !== null) {
                            $room = $rooms->firstWhere('id', $choice[$i]);
                            $allocRooms[] = $room;
                            $i -= $room->people_count;
                        }

                        // Zlicz ile razy każdy pokój został użyty
                        $roomCounts = [];
                        foreach ($allocRooms as $room) {
                            $roomCounts[$room->id] = ($roomCounts[$room->id] ?? 0) + 1;
                        }

                        $peopleAssigned = 0;
                        foreach ($roomCounts as $roomId => $count) {
                            $room = $rooms->firstWhere('id', $roomId);
                            for ($j = 0; $j < $count; $j++) {
                                $alloc = [
                                    'qty' => 0,
                                    'gratis' => 0,
                                    'staff' => 0,
                                    'driver' => 0,
                                ];

                                // Przydzielaj tylko tyle osób, ile jeszcze potrzeba
                                $toAssign = min($room->people_count, $peopleCount - $peopleAssigned);
                                $alloc[$groupType] = $toAssign;

                                $roomAlloc[] = [
                                    'room' => $room,
                                    'alloc' => $alloc,
                                    'total_people' => $toAssign,
                                    'cost' => $room->price,
                                    'currency' => $room->currency,
                                    'group_type' => $groupType,
                                    'room_count' => 1,
                                ];

                                $dayTotal[$room->currency] = ($dayTotal[$room->currency] ?? 0) + $room->price;
                                $roomTypeCount[$room->id]++;
                                $peopleAssigned += $toAssign;

                                if ($peopleAssigned >= $peopleCount) break 2;
                            }
                        }
                    }
                }

                $hotelStructure[] = [
                    'day' => $hotelDay->day,
                    'rooms' => $roomAlloc,
                    'day_total' => $dayTotal,
                ];

                // Dodaj do ogólnej sumy kosztów noclegów
                foreach ($dayTotal as $cur => $val) {
                    if ($cur === 'PLN') {
                        $plnPoints[] = [
                            'name' => 'Noclegi - dzień ' . $hotelDay->day,
                            'unit_price' => null,
                            'group_size' => null,
                            'cost' => $val,
                            'is_child' => false,
                            'currency_symbol' => 'PLN',
                        ];
                        $plnTotal += $val;
                    } else {
                        $currenciesPoints[$cur][] = [
                            'name' => 'Noclegi - dzień ' . $hotelDay->day,
                            'unit_price' => null,
                            'group_size' => null,
                            'cost' => $val,
                            'is_child' => false,
                            'currency_symbol' => $cur,
                        ];
                        $currenciesTotals[$cur] = ($currenciesTotals[$cur] ?? 0) + $val;
                    }
                }
            }
            $calculations[$qty]['hotel_structure'] = $hotelStructure;

            // DODAJ KOSZT TRANSPORTU DO WALUTY AUTOKARU - PRZED obliczeniem narzutu
            if ($bus && $busTransportCost !== null) {
                if ($busCurrency === 'PLN') {
                    $plnPoints[] = [
                        'name' => 'Koszt transportu (autokar)',
                        'unit_price' => null,
                        'group_size' => null,
                        'cost' => $busTransportCost * $busMultiplier,
                        'is_child' => false,
                        'currency_symbol' => $busCurrency
                    ];
                    $plnTotal += $busTransportCost * $busMultiplier;
                } else {
                    if (!isset($currenciesPoints[$busCurrency])) {
                        $currenciesPoints[$busCurrency] = [];
                        $currenciesTotals[$busCurrency] = 0;
                    }
                    $currenciesPoints[$busCurrency][] = [
                        'name' => 'Koszt transportu (autokar)',
                        'unit_price' => null,
                        'group_size' => null,
                        'cost' => $busTransportCost * $busMultiplier,
                        'is_child' => false,
                        'currency_symbol' => $busCurrency
                    ];
                    $currenciesTotals[$busCurrency] += $busTransportCost * $busMultiplier;
                }
            }            // OBLICZ NARZUT - po dodaniu wszystkich kosztów (włącznie z transportem)
            $markup = $this->record->markup ?: \App\Models\Markup::where('is_default', true)->first();

            // Przygotuj tymczasowe dane do obliczenia narzutu
            $tempCalculation = [
                'PLN' => ['total' => $plnTotal]
            ];
            foreach ($currenciesTotals as $code => $total) {
                if ($code !== 'PLN') {
                    $tempCalculation[$code] = ['total' => $total];
                }
            }

            $totalPLNBeforeMarkup = $this->calculateTotalInPLN($tempCalculation);
            $markupAmount = $this->calculateMarkup($totalPLNBeforeMarkup);
            $markupCalculation = ['amount' => $markupAmount];

            // Oblicz podatki
            $taxes = $this->record->taxes ?? collect();
            $taxCalculations = [];
            $totalTaxAmount = 0;

            foreach ($taxes as $tax) {
                if (!$tax->is_active) continue;

                $taxAmount = $tax->calculateTaxAmount($plnTotal, $markupCalculation['amount']);
                if ($taxAmount > 0) {
                    $taxCalculations[] = [
                        'name' => $tax->name,
                        'percentage' => $tax->percentage,
                        'amount' => $taxAmount,
                        'apply_to_base' => $tax->apply_to_base,
                        'apply_to_markup' => $tax->apply_to_markup
                    ];
                    $totalTaxAmount += $taxAmount;
                }
            }

            // Dodaj narzut do obliczeń
            $calculations[$qty]['markup'] = $markupCalculation;

            // Dodaj podatki do obliczeń
            // Dodaj informacje o narzucie
            $markupPercent = $this->record->markup_percent ?? 20;
            $calculations[$qty]['markup'] = [
                'amount' => $markupCalculation['amount'],
                'percent_applied' => $markupPercent,
                'discount_applied' => false, // uproszczona wersja - bez skomplikowanej logiki rabatów
                'discount_percent' => 0,
                'min_daily_applied' => false
            ];

            $calculations[$qty]['taxes'] = [
                'total_amount' => $totalTaxAmount,
                'breakdown' => $taxCalculations
            ];

            // PLN na pierwszym miejscu - BEZ narzutu w points
            $calculations[$qty]['PLN'] = [
                'total' => $plnTotal + $markupCalculation['amount'] + $totalTaxAmount, // suma z narzutem i podatkami
                'total_before_markup' => $plnTotal, // suma bez narzutu
                'total_before_tax' => $plnTotal + $markupCalculation['amount'], // suma z narzutem ale bez podatków
                'points' => $plnPoints // punkty bez narzutu
            ];
            foreach ($currenciesTotals as $code => $total) {
                if ($code !== 'PLN') {
                    $calculations[$qty][$code] = [
                        'total' => $total,
                        'points' => $currenciesPoints[$code] ?? []
                    ];
                }
            }
        }

        // Sortuj wyniki po ilości osób (klucz qty)
        ksort($calculations);
        return $calculations;
    }

    public function recalculatePrices(): void
    {
        try {
            if (!$this->record) return;

            \Illuminate\Support\Facades\Log::info("Starting price recalculation for template {$this->record->id}");

            // KROK 1: Usuń duplikaty z bazy danych
            $this->removeDuplicatePrices();

            // KROK 2: Pobierz szczegółowe kalkulacje
            $detailedCalculations = $this->getDetailedCalculations();
            
            if (empty($detailedCalculations)) {
                Notification::make()
                    ->title('Brak danych do kalkulacji')
                    ->body('Nie można przeliczać cen - brak punktów programu lub konfiguracji.')
                    ->warning()
                    ->send();
                return;
            }

            // KROK 3: Znajdź polską walutę
            $plnCurrency = \App\Models\Currency::where('code', 'PLN')
                ->orWhere('name', 'like', '%polski%złoty%')
                ->first();
                
            if (!$plnCurrency) {
                throw new \Exception('Nie znaleziono polskiej waluty (PLN) w systemie');
            }

            // KROK 4: Przelicz i zapisz ceny (modyfikuj istniejące lub utwórz nowe)
            $updatedCount = 0;
            $createdCount = 0;

            foreach ($detailedCalculations as $qty => $currencyData) {
                $plnData = $currencyData['PLN'] ?? null;
                if (!$plnData) continue;

                // Znajdź istniejący rekord lub utwórz nowy
                $priceRecord = EventTemplatePricePerPerson::where([
                    'event_template_id' => $this->record->id,
                    'event_template_qty_id' => $this->getQtyId($qty),
                    'currency_id' => $plnCurrency->id,
                    'start_place_id' => $this->startPlaceId
                ])->first();

                $priceData = [
                    'price_base' => $this->ceilTo5($plnData['total'] ?? 0),
                    'markup_amount' => $this->ceilTo5($this->calculateMarkup($plnData['total'] ?? 0)),
                    'tax_amount' => $this->ceilTo5($this->calculateTax($plnData['total'] ?? 0)),
                    'price_with_tax' => $this->ceilTo5($this->calculatePriceWithTax($plnData['total'] ?? 0)),
                    'price_per_person' => $this->ceilTo5($this->calculatePricePerPerson($plnData['total'] ?? 0, $qty)),
                    'transport_cost' => $this->ceilTo5($this->calculateTransportCost($qty)),
                    'tax_breakdown' => $this->prepareTaxBreakdown(),
                    'updated_at' => now()
                ];

                if ($priceRecord) {
                    // MODYFIKUJ istniejący rekord
                    $priceRecord->update($priceData);
                    $updatedCount++;
                    \Illuminate\Support\Facades\Log::info("Updated price for qty {$qty}");
                } else {
                    // UTWÓRZ nowy rekord
                    EventTemplatePricePerPerson::create(array_merge($priceData, [
                        'event_template_id' => $this->record->id,
                        'event_template_qty_id' => $this->getQtyId($qty),
                        'currency_id' => $plnCurrency->id,
                        'start_place_id' => $this->startPlaceId,
                        'created_at' => now()
                    ]));
                    $createdCount++;
                    \Illuminate\Support\Facades\Log::info("Created price for qty {$qty}");
                }
            }

            // KROK 5: Refresh cached data
            unset($this->cachedPrices);
            unset($this->cachedCalculations);
            $this->prices = $this->getPricesProperty();

            Notification::make()
                ->title('Ceny zostały przeliczone!')
                ->body("Zaktualizowano: {$updatedCount}, Utworzono: {$createdCount} rekordów cen")
                ->success()
                ->send();

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Error recalculating prices: ' . $e->getMessage());
            Notification::make()
                ->title('Błąd podczas przeliczania cen')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Usuwa duplikaty cen z bazy danych - zachowuje tylko najnowsze rekordy (globalnie)
     */
    public static function removeDuplicatePrices(): void
    {
        \Illuminate\Support\Facades\Log::info("Removing duplicate prices globally");

        // Znajdź duplikaty - rekordy z tą samą kombinacją kluczy (dla wszystkich szablonów)
        $duplicateGroups = EventTemplatePricePerPerson::select('event_template_id', 'event_template_qty_id', 'currency_id', 'start_place_id')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('event_template_id', 'event_template_qty_id', 'currency_id', 'start_place_id')
            ->having('count', '>', 1)
            ->get();

        $removedCount = 0;
        foreach ($duplicateGroups as $group) {
            // Dla każdej grupy duplikatów, zachowaj tylko najnowszy rekord
            $records = EventTemplatePricePerPerson::where([
                'event_template_id' => $group->event_template_id,
                'event_template_qty_id' => $group->event_template_qty_id,
                'currency_id' => $group->currency_id,
                'start_place_id' => $group->start_place_id
            ])->orderBy('created_at', 'desc')->get();

            // Usuń wszystkie oprócz pierwszego (najnowszego)
            for ($i = 1; $i < $records->count(); $i++) {
                $records[$i]->delete();
                $removedCount++;
            }
        }

        if ($removedCount > 0) {
            \Illuminate\Support\Facades\Log::info("Removed {$removedCount} duplicate price records globally");
        }
    }

    /**
     * Pomocnicze metody do kalkulacji cen
     */
    private function getQtyId($qty): int
    {
        $qtyRecord = \App\Models\EventTemplateQty::where('qty', $qty)->first();
        return $qtyRecord ? $qtyRecord->id : 0;
    }

    private function calculateMarkup($basePrice): float
    {
        $markupPercent = $this->record->markup_percent ?? 20; // domyślnie 20%
        return $basePrice * ($markupPercent / 100);
    }

    private function calculateTax($basePrice): float
    {
        $taxPercent = 23; // VAT 23%
        $markupAmount = $this->calculateMarkup($basePrice);
        return ($basePrice + $markupAmount) * ($taxPercent / 100);
    }

    private function calculatePriceWithTax($basePrice): float
    {
        return $basePrice + $this->calculateMarkup($basePrice) + $this->calculateTax($basePrice);
    }

    private function calculatePricePerPerson($totalPrice, $qty): float
    {
        return $qty > 0 ? $totalPrice / $qty : 0;
    }

    private function calculateTransportCost($qty): float
    {
        // Algorytm: (dojazd + program + powrót) * 1.1 + 50 km, liczba autobusów, limity km, nadmiarowe km
        $bus = $this->record->bus;
        if (!$bus) return 0;
        $duration = $this->record->duration_days ?? 1;
        $qtyVariant = \App\Models\EventTemplateQty::where('qty', $qty)->first();
        $totalPeople = $qty;
        if ($qtyVariant) {
            $totalPeople += ($qtyVariant->gratis ?? 0) + ($qtyVariant->staff ?? 0) + ($qtyVariant->driver ?? 0);
        }
        $busCapacity = $bus->capacity > 0 ? $bus->capacity : 50;
        $busCount = (int) ceil($totalPeople / $busCapacity);
        // Suma km: dojazd + program + powrót
        $transferKm = $this->record->transfer_km ?? 0;
        $programKm = $this->record->program_km ?? 0;
        $totalKm = ($transferKm * 2) + $programKm;
        $totalKm = $totalKm * 1.1 + 50;
        $includedKm = $duration * $bus->package_km_per_day;
        $baseCost = $duration * $bus->package_price_per_day;
        if ($totalKm <= $includedKm) {
            return $baseCost * $busCount;
        } else {
            $extraKm = $totalKm - $includedKm;
            return ($baseCost + ($extraKm * $bus->extra_km_price)) * $busCount;
        }
    }

    private function prepareTaxBreakdown(): array
    {
        return [
            ['tax_name' => 'VAT 23%', 'tax_amount' => 23]
        ];
    }
    public function calculatePointCost($qty, $groupSize, $unitPrice)
    {
        return ceil($qty / $groupSize) * $unitPrice;
    }

    /**
     * Oblicza całkowitą sumę w PLN dla danego wariantu qty
     */
    private function calculateTotalInPLN($qtyCalculation)
    {
        $totalPLN = 0;

        // Dodaj PLN bezpośrednio
        if (isset($qtyCalculation['PLN']['total'])) {
            $totalPLN += $qtyCalculation['PLN']['total'];
        } elseif (isset($qtyCalculation['PLN']) && is_numeric($qtyCalculation['PLN'])) {
            // Obsługa gdy przekazano bezpośrednio wartość
            $totalPLN += $qtyCalculation['PLN'];
        }

        // Przelicz inne waluty na PLN używając kursów z tabeli currencies
        foreach ($qtyCalculation as $currencyCode => $data) {
            if ($currencyCode === 'PLN' || $currencyCode === 'hotel_structure') {
                continue;
            }

            $amount = 0;
            if (is_array($data) && isset($data['total'])) {
                $amount = $data['total'];
            } elseif (is_numeric($data)) {
                $amount = $data;
            }

            if ($amount > 0) {
                // Znajdź kurs dla tej waluty
                $currency = \App\Models\Currency::where('symbol', $currencyCode)->first();
                if ($currency && $currency->exchange_rate) {
                    $totalPLN += $amount * $currency->exchange_rate;
                }
            }
        }
        return $totalPLN;
    }
}
