<?php

return [
    // Tanggal operasional Hari 1 untuk seluruh POSKO: A=Tim 1, B=Tim 2, C=Tim 3, libur=Tim 4.
    // Sengaja tidak ada default agar rotasi tidak salah sebelum tanggal aktivasi diset.
    'posko_crew_anchor_date' => env('POSKO_CREW_ANCHOR_DATE'),
];
