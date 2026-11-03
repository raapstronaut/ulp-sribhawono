<?php

return [
    /*
    | Kotak wilayah operasional ULP Sribhawono untuk menilai kewajaran koordinat di Peta Jaringan.
    | Diturunkan dari sebaran 17.059 aset ASSET_RAW (lat -5,73..-4,92, lon 105,45..105,86) ditambah
    | margin sekitar 0,2 derajat (±22 km). Bukan batas administrasi resmi; hanya memengaruhi tampilan
    | peta, nilai koordinat di database dan sumber tidak diubah.
    */
    'operational_bounds' => [
        'south' => -5.95,
        'north' => -4.70,
        'west' => 105.25,
        'east' => 106.10,
    ],
];
