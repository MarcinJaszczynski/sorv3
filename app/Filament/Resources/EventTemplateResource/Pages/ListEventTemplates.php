<?php

namespace App\Filament\Resources\EventTemplateResource\Pages;

use App\Filament\Resources\EventTemplateResource;
use App\Models\EventTemplate;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Actions\Action as TableAction;

class ListEventTemplates extends ListRecords
{
    protected static string $resource = EventTemplateResource::class;

    protected static string $defaultPaginationPageOption = '25';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('recalculateAllPrices')
                ->label('Przelicz ceny dla wszystkich')
                ->icon('heroicon-o-calculator')
                ->color('success')
                ->requiresConfirmation()
                ->action(function () {
                    \Illuminate\Support\Facades\Log::info('Rozpoczęto przeliczanie cen dla wszystkich szablonów');
                    $templates = \App\Models\EventTemplate::all();
                    \Illuminate\Support\Facades\Log::info('Znaleziono ' . $templates->count() . ' szablonów');
                    $calculator = new \App\Services\EventTemplatePriceCalculator();
                    foreach ($templates as $template) {
                        \Illuminate\Support\Facades\Log::info('Przeliczanie cen dla szablonu: ' . $template->id . ' (' . $template->name . ')');
                        try {
                            $calculator->calculateAndSave($template);
                            \Illuminate\Support\Facades\Log::info('Zakończono przeliczanie dla szablonu: ' . $template->id);
                        } catch (\Exception $e) {
                            \Illuminate\Support\Facades\Log::error('Błąd przy przeliczaniu szablonu ' . $template->id . ': ' . $e->getMessage());
                        }
                    }
                    \Illuminate\Support\Facades\Log::info('Zakończono przeliczanie cen dla wszystkich szablonów');
                    \Filament\Notifications\Notification::make()
                        ->title('Ceny zostały przeliczone dla wszystkich szablonów!')
                        ->success()
                        ->send();
                }),
        ];
    }

    protected function getTableActions(): array
    {
        return [
            TableAction::make('edit')
                ->url(fn($record) => static::getResource()::getUrl('edit', ['record' => $record->id])),
            TableAction::make('delete'),
            TableAction::make('clone')
                ->label('Klonuj')
                ->icon('heroicon-o-document-duplicate')
                ->action(function ($record) {
                    // Załaduj wszystkie relacje
                    $record->load([
                        'tags',
                        'programPoints',
                        'dayInsurances.insurance',
                        'hotelDays',
                        'startingPlaceAvailabilities',
                        'taxes',
                        'pricesPerPerson' // zamiast qtyVariants
                    ]);

                    $clone = EventTemplate::create([
                        'name' => $record->name . ' (copy)',
                        'subtitle' => $record->subtitle,
                        'slug' => $record->slug . '-copy-' . uniqid(),
                        'duration_days' => $record->duration_days,
                        'is_active' => $record->is_active,
                        'featured_image' => $record->featured_image,
                        'event_description' => $record->event_description,
                        'gallery' => $record->gallery,
                        'office_description' => $record->office_description,
                        'notes' => $record->notes,
                        'transfer_km' => $record->transfer_km,
                        'program_km' => $record->program_km,
                        'bus_id' => $record->bus_id,
                        'markup_id' => $record->markup_id,
                        'start_place_id' => $record->start_place_id,
                        'end_place_id' => $record->end_place_id,
                        'transport_notes' => $record->transport_notes,
                        'seo_title' => $record->seo_title,
                        'seo_description' => $record->seo_description,
                        'seo_keywords' => $record->seo_keywords,
                    ]);

                    // Klonuj tagi
                    $clone->tags()->sync($record->tags->pluck('id')->toArray());

                    // Klonuj punkty programu (pivot)
                    foreach ($record->programPoints as $point) {
                        $clone->programPoints()->attach($point->id, [
                            'day' => $point->pivot->day,
                            'order' => $point->pivot->order,
                            'notes' => $point->pivot->notes,
                            'include_in_program' => $point->pivot->include_in_program,
                            'include_in_calculation' => $point->pivot->include_in_calculation,
                            'active' => $point->pivot->active,
                        ]);
                    }

                    // Klonuj ceny za osobę (zamiast wariantów QTY)
                    // Najpierw usuń istniejące ceny, jeśli istnieją
                    $clone->pricesPerPerson()->delete();

                    foreach ($record->pricesPerPerson as $price) {
                        $clone->pricesPerPerson()->create([
                            'event_template_qty_id' => $price->event_template_qty_id,
                            'currency_id' => $price->currency_id,
                            'start_place_id' => $price->start_place_id,
                            'price_per_person' => $price->price_per_person,
                        ]);
                    }

                    // Klonuj ubezpieczenia dni
                    foreach ($record->dayInsurances as $dayInsurance) {
                        $clone->dayInsurances()->create([
                            'day' => $dayInsurance->day,
                            'insurance_id' => $dayInsurance->insurance_id,
                        ]);
                    }

                    // Klonuj dni hotelowe - jeśli nie ma w bazie, generuj na podstawie duration_days
                    if ($record->hotelDays->count() > 0) {
                        // Kopiuj z bazy
                        foreach ($record->hotelDays as $hotelDay) {
                            $clone->hotelDays()->create([
                                'day' => $hotelDay->day,
                                'hotel_room_ids_qty' => $hotelDay->hotel_room_ids_qty ?? [],
                                'hotel_room_ids_gratis' => $hotelDay->hotel_room_ids_gratis ?? [],
                                'hotel_room_ids_staff' => $hotelDay->hotel_room_ids_staff ?? [],
                                'hotel_room_ids_driver' => $hotelDay->hotel_room_ids_driver ?? [],
                            ]);
                        }
                    } else {
                        // Generuj na podstawie duration_days
                        $nights = max(0, $record->duration_days - 1);
                        for ($i = 1; $i <= $nights; $i++) {
                            $clone->hotelDays()->create([
                                'day' => $i,
                                'hotel_room_ids_qty' => [],
                                'hotel_room_ids_gratis' => [],
                                'hotel_room_ids_staff' => [],
                                'hotel_room_ids_driver' => [],
                            ]);
                        }
                    }

                    // Klonuj dostępność miejsc startowych
                    foreach ($record->startingPlaceAvailabilities as $availability) {
                        $clone->startingPlaceAvailabilities()->create([
                            'start_place_id' => $availability->start_place_id,
                            'end_place_id' => $availability->end_place_id,
                            'available' => $availability->available,
                            'note' => $availability->note,
                        ]);
                    }

                    // Klonuj podatki
                    $clone->taxes()->sync($record->taxes->pluck('id')->toArray());

                    // Dodaj powiadomienie o udanym klonowaniu
                    \Filament\Notifications\Notification::make()
                        ->title('Szablon został pomyślnie sklonowany!')
                        ->success()
                        ->send();

                    // Przekieruj do edycji nowego klona
                    return redirect(static::getResource()::getUrl('edit', ['record' => $clone->id]));
                }),
        ];
    }
}
