<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Zttp\Zttp;
use Zttp\ZttpResponse;

function weather($lat, $lon) {
    // Openweather rouns the number to 2 decimals, so we do the same, for better cache
    $lat = round($lat, 2);
    $lon = round($lon, 2);

    return Cache::remember("weather_{$lat}_{$lon}", 60 * 60, function() use ($lat, $lon) {
        $ow_api = env('OPENWEATER_API_KEY');
        $ow_api_url = env('OPENWEATHER_API_URL');

        $data = Zttp::get("{$ow_api_url}/onecall?lat=$lat&lon=$lon&appid=$ow_api")->json();

        return [
            'lat' => $data['lat'],
            'lon' => $data['lon'],
            'timezone' => $data['timezone'],
            'timezone_offset' => $data['timezone_offset'],
            'current' => $data['current'],
            'hourly' => $data['hourly'],
        ];
    });
}

function places($location, $population = 1000, $radius = 100 * 1000) {
    return Cache::rememberForever("radius_{$radius}_{$population}_{$location}", function() use($radius, $population, $location) {
        $overpass_api = env('OVERPASS_API_URL');

        $cities = Zttp::get("{$overpass_api}/interpreter?data=[out:json];node[%22place%22](around:$radius,$location);out;")->json()["elements"];

        // We filter out tiny towns with less than 2500 population
        return collect($cities)->filter(function($city) {
                return in_array($city['tags']['place'], ['town', 'city']) && array_key_exists('population', $city['tags']) && $city['tags']['population'] > 2500;
            })->map(function($city) {

                // If there is no name, set one
                if (!array_key_exists('name_int', $city['tags']) && !array_key_exists('name', $city['tags'])) {
                    $name = 'Nameless';
                } else {
                    $name = array_key_exists('name_int', $city['tags']) ? $city['tags']['name_int'] : $city['tags']['name'];
                }

                return [
                    'id' => $city['id'],
                    'lat' => $city['lat'],
                    'lon' => $city['lon'],
                    'name' => $name,
                    'population' => $city['tags']['population'],
                    'place' => $city['tags']['place'],
                ];
            })->filter(function ($city) use ($population) {
                return $city['population'] > $population;
            });
    });
}

function myLocation($lonlat) {
    return Cache::rememberForever("name_{$lonlat}", function() use ($lonlat) {
        $overpass_api = env('OVERPASS_API_URL');
        $my_location = Zttp::get("{$overpass_api}/interpreter?data=[out:json];is_in($lonlat);area._[admin_level=8];out;")->json();

        return $my_location['elements'][0]['tags']['name'];
    });
}

function osrm($from_location, $places) {
    $parameters = $from_location;

    foreach($places as $place) {
        $parameters = "{$parameters};{$place["lat"]},{$place["lon"]}";
    }

    return Zttp::get("http://router.project-osrm.org/table/v1/driving/${parameters}?sources=0&annotations=duration,distance")->json();
}

Route::get('/weather/{location}', function($location) {
    // 1. Fetch the nearest city
    $my_location = myLocation($location);

    // 2. create a circle with cities
    $places = places($location, 25000);

    // 3. Fetch weather data for those cities
    $places = $places->map(function($place) {
        return array_merge($place, ['weather' => weather($place['lat'], $place['lon'])]);
    })->toArray();

    // 4. Fetch OSRM data for those cities
    // This is not fetched with the locations data itself, as later on we can use traffic data as well
    $osrm = osrm($location, $places);

    $result = [];
    $i = 0;

    foreach($places as $place) {
        array_push($result, array_merge($place, [
            'duration' => $osrm["durations"][0][$i + 1],
            'distance' => $osrm["distances"][0][$i + 1]
            ]
        ));
        $i++;
    }

    usort($result, function($a, $b) {
        $ad = $a["duration"];
        $bd = $b["duration"];

        if ($ad == $bd)
            return 0;
        return ($ad < $bd) ? -1 : 1;
    });

    return $result;
});
