<?php

namespace App\Filament\Resources\PlaceDistanceResource\Pages;

use App\Filament\Resources\PlaceDistanceResource;
use Filament\Resources\Pages\ListRecords;

class ListPlaceDistances extends ListRecords
{
    protected static string $resource = PlaceDistanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\CreateAction::make(),
            \Filament\Actions\Action::make('updateDistances')
                ->label('Aktualizuj odległości z API')
                ->icon('heroicon-o-arrow-path')
                ->action('updateDistances'),
        ];
    }

    public function updateDistances()
    {
        $apiKey = '5b3ce3597851110001cf62489885073b636a44e3ac9774af529a3c40';
        $places = \App\Models\Place::all();
        foreach ($places as $from) {
            foreach ($places as $to) {
                if ($from->id === $to->id) continue;
                $existing = \App\Models\PlaceDistance::where('from_place_id', $from->id)->where('to_place_id', $to->id)->first();
                if ($existing && $existing->distance_km) continue;
                $distance = $this->fetchDistance($from, $to, $apiKey);
                if ($distance !== null) {
                    \App\Models\PlaceDistance::updateOrCreate([
                        'from_place_id' => $from->id,
                        'to_place_id' => $to->id,
                    ], [
                        'distance_km' => $distance,
                        'api_source' => 'openrouteservice',
                    ]);
                }
            }
        }
        \Filament\Notifications\Notification::make()
            ->title('Odległości zostały zaktualizowane z API.')
            ->success()
            ->send();
    }

    protected function fetchDistance($from, $to, $apiKey)
    {
        if (!$from->latitude || !$from->longitude || !$to->latitude || !$to->longitude) return null;
        $url = 'https://api.openrouteservice.org/v2/directions/driving-car?api_key=' . $apiKey . '&start=' . $from->longitude . ',' . $from->latitude . '&end=' . $to->longitude . ',' . $to->latitude;
        try {
            $response = file_get_contents($url);
            $data = json_decode($response, true);
            if (isset($data['features'][0]['properties']['segments'][0]['distance'])) {
                return round($data['features'][0]['properties']['segments'][0]['distance'] / 1000, 2);
            }
        } catch (\Exception $e) {
            return null;
        }
        return null;
    }
}
