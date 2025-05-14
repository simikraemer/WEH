<?php
$liveStateFile = '/WEH/PHP/sekofinale/live_state.json';
$csvFile = '/WEH/PHP/sekofinale/kategorien.csv';

// Kategorien aus der CSV laden
$categories = [];
if (file_exists($csvFile) && ($handle = fopen($csvFile, "r")) !== false) {
    $header = fgetcsv($handle, 10000, ";");
    foreach ($header as $col) {
        $categories[] = trim($col);
    }
    fclose($handle);
}

// Basis-Zustand mit Kategorien
$resetState = [
    'category' => '',
    'answers' => [],
    'players' => [
        [ 'name' => '', 'score' => 0, 'out' => false ],
        [ 'name' => '', 'score' => 0, 'out' => false ],
        [ 'name' => '', 'score' => 0, 'out' => false ],
        [ 'name' => '', 'score' => 0, 'out' => false ],
        [ 'name' => '', 'score' => 0, 'out' => false ]
    ],
    'currentPlayerIndex' => 0,
    'usedCategories' => [],
    'allOptionsUsed' => false,
    'allCategories' => $categories
];

file_put_contents($liveStateFile, json_encode($resetState, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
