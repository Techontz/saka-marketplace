<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\District;
use App\Models\Region;
use App\Models\Ward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Tanzania administrative hierarchy: Region > District > Ward.
 *
 * NOTE: saka_api was inspected under Milestone 5 decision 1 — its seeder is an
 * empty stub, so there was nothing to reuse. This data is authored fresh.
 *
 * All 31 regions are seeded. Districts and wards are seeded in full for Dar es
 * Salaam (the launch market, and the location of every listing in the current
 * frontend mock data) and at district level for the other major regions. Ward
 * coverage for the rest of the country is a data-import task, not a code task.
 */
class LocationSeeder extends Seeder
{
    /** @var array<string, array<string, array<int, string>>> */
    private const TANZANIA = [
        'Dar es Salaam' => [
            'Ilala' => [
                'Kariakoo', 'Upanga East', 'Upanga West', 'Kisutu', 'Mchikichini',
                'Jangwani', 'Buguruni', 'Ilala', 'Gerezani', 'Kivukoni', 'Tabata',
            ],
            'Kinondoni' => [
                'Masaki', 'Msasani', 'Mikocheni', 'Kinondoni', 'Oysterbay',
                'Mwananyamala', 'Magomeni', 'Kijitonyama', 'Hananasif', 'Ndugumbi',
            ],
            'Temeke' => [
                'Chang\'ombe', 'Keko', 'Mtoni', 'Temeke', 'Azimio', 'Miburani', 'Sandali',
            ],
            'Ubungo' => [
                'Ubungo', 'Sinza', 'Kimara', 'Mburahati', 'Manzese', 'Makuburi', 'Goba',
            ],
            'Kigamboni' => [
                'Kigamboni', 'Vijibweni', 'Somangila', 'Kibada', 'Tungi',
            ],
        ],
        'Arusha' => [
            'Arusha City' => ['Kaloleni', 'Levolosi', 'Sekei', 'Themi', 'Kati'],
            'Arusha Rural' => [], 'Meru' => [], 'Karatu' => [], 'Monduli' => [],
            'Ngorongoro' => [], 'Longido' => [],
        ],
        'Mwanza' => [
            'Nyamagana' => ['Mkuyuni', 'Pamba', 'Mirongo', 'Isamilo'],
            'Ilemela' => [], 'Sengerema' => [], 'Magu' => [], 'Misungwi' => [],
            'Kwimba' => [], 'Ukerewe' => [], 'Buchosa' => [],
        ],
        'Dodoma' => [
            'Dodoma City' => ['Makole', 'Kikuyu', 'Viwandani', 'Chamwino'],
            'Bahi' => [], 'Chamwino' => [], 'Chemba' => [], 'Kondoa' => [],
            'Kongwa' => [], 'Mpwapwa' => [],
        ],
        'Mbeya' => [
            'Mbeya City' => ['Sisimba', 'Ruanda', 'Iyunga', 'Forest'],
            'Mbeya Rural' => [], 'Chunya' => [], 'Kyela' => [], 'Rungwe' => [], 'Mbarali' => [],
        ],
        'Kilimanjaro' => [
            'Moshi Municipal' => ['Kiusa', 'Majengo', 'Kilimanjaro', 'Msaranga'],
            'Moshi Rural' => [], 'Hai' => [], 'Rombo' => [], 'Same' => [], 'Mwanga' => [], 'Siha' => [],
        ],
        'Tanga' => [
            'Tanga City' => ['Ngamiani', 'Chumbageni', 'Makorora'],
            'Muheza' => [], 'Korogwe' => [], 'Lushoto' => [], 'Pangani' => [], 'Handeni' => [], 'Kilindi' => [],
        ],
        'Morogoro' => [
            'Morogoro Municipal' => ['Sabasaba', 'Kilakala', 'Mafiga'],
            'Morogoro Rural' => [], 'Kilosa' => [], 'Kilombero' => [], 'Ulanga' => [], 'Mvomero' => [],
        ],
        'Mjini Magharibi' => [
            'Mjini' => ['Stone Town', 'Malindi', 'Shangani'],
            'Magharibi A' => [], 'Magharibi B' => [],
        ],
        'Pwani' => ['Kibaha' => [], 'Bagamoyo' => [], 'Kisarawe' => [], 'Mkuranga' => [], 'Rufiji' => [], 'Mafia' => []],
        'Geita' => ['Geita Town' => [], 'Bukombe' => [], 'Chato' => [], 'Mbogwe' => [], 'Nyang\'hwale' => []],
        'Iringa' => ['Iringa Municipal' => [], 'Iringa Rural' => [], 'Kilolo' => [], 'Mufindi' => []],
        'Kagera' => ['Bukoba Municipal' => [], 'Bukoba Rural' => [], 'Karagwe' => [], 'Muleba' => [], 'Ngara' => [], 'Biharamulo' => []],
        'Katavi' => ['Mpanda' => [], 'Mlele' => [], 'Tanganyika' => []],
        'Kigoma' => ['Kigoma Municipal' => [], 'Kasulu' => [], 'Kibondo' => [], 'Uvinza' => [], 'Buhigwe' => []],
        'Lindi' => ['Lindi Municipal' => [], 'Kilwa' => [], 'Nachingwea' => [], 'Ruangwa' => [], 'Liwale' => []],
        'Manyara' => ['Babati Town' => [], 'Babati Rural' => [], 'Hanang' => [], 'Kiteto' => [], 'Mbulu' => [], 'Simanjiro' => []],
        'Mara' => ['Musoma Municipal' => [], 'Musoma Rural' => [], 'Bunda' => [], 'Serengeti' => [], 'Tarime' => [], 'Rorya' => []],
        'Mtwara' => ['Mtwara Municipal' => [], 'Mtwara Rural' => [], 'Masasi' => [], 'Newala' => [], 'Tandahimba' => [], 'Nanyumbu' => []],
        'Njombe' => ['Njombe Town' => [], 'Njombe Rural' => [], 'Makete' => [], 'Ludewa' => [], 'Wanging\'ombe' => []],
        'Rukwa' => ['Sumbawanga Municipal' => [], 'Sumbawanga Rural' => [], 'Nkasi' => [], 'Kalambo' => []],
        'Ruvuma' => ['Songea Municipal' => [], 'Songea Rural' => [], 'Mbinga' => [], 'Tunduru' => [], 'Namtumbo' => []],
        'Shinyanga' => ['Shinyanga Municipal' => [], 'Shinyanga Rural' => [], 'Kahama' => [], 'Kishapu' => []],
        'Simiyu' => ['Bariadi' => [], 'Busega' => [], 'Itilima' => [], 'Maswa' => [], 'Meatu' => []],
        'Singida' => ['Singida Municipal' => [], 'Singida Rural' => [], 'Iramba' => [], 'Manyoni' => [], 'Mkalama' => []],
        'Songwe' => ['Vwawa' => [], 'Ileje' => [], 'Mbozi' => [], 'Momba' => [], 'Songwe' => []],
        'Tabora' => ['Tabora Municipal' => [], 'Igunga' => [], 'Nzega' => [], 'Sikonge' => [], 'Urambo' => [], 'Uyui' => []],
        'Pemba North' => ['Wete' => [], 'Micheweni' => []],
        'Pemba South' => ['Chake Chake' => [], 'Mkoani' => []],
        'Unguja North' => ['Kaskazini A' => [], 'Kaskazini B' => []],
        'Unguja South' => ['Kusini' => [], 'Kati' => []],
    ];

    /** Approximate region centroids, used as a map default when a listing has no coordinates. */
    private const CENTROIDS = [
        'Dar es Salaam' => [-6.7924, 39.2083],
        'Arusha' => [-3.3869, 36.6830],
        'Mwanza' => [-2.5164, 32.9175],
        'Dodoma' => [-6.1630, 35.7516],
        'Mbeya' => [-8.9094, 33.4608],
        'Kilimanjaro' => [-3.3349, 37.3403],
        'Tanga' => [-5.0689, 39.0988],
        'Morogoro' => [-6.8221, 37.6612],
        'Mjini Magharibi' => [-6.1659, 39.2026],
    ];

    public function run(): void
    {
        $regionCount = 0;
        $districtCount = 0;
        $wardCount = 0;

        foreach (self::TANZANIA as $regionName => $districts) {
            $centroid = self::CENTROIDS[$regionName] ?? [null, null];

            $region = Region::updateOrCreate(
                ['country_code' => 'TZ', 'slug' => Str::slug($regionName)],
                [
                    'name' => $regionName,
                    'latitude' => $centroid[0],
                    'longitude' => $centroid[1],
                    'is_active' => true,
                ],
            );
            $regionCount++;

            foreach ($districts as $districtName => $wards) {
                $district = District::updateOrCreate(
                    ['region_id' => $region->id, 'slug' => Str::slug($districtName)],
                    ['name' => $districtName, 'is_active' => true],
                );
                $districtCount++;

                foreach ($wards as $wardName) {
                    Ward::updateOrCreate(
                        ['district_id' => $district->id, 'slug' => Str::slug($wardName)],
                        ['name' => $wardName, 'is_active' => true],
                    );
                    $wardCount++;
                }
            }
        }

        $this->command->info("  Seeded {$regionCount} regions, {$districtCount} districts, {$wardCount} wards.");
    }
}
