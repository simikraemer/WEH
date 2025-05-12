<?php
// Pfad zur CSV mit allen Kategorien
$csvFile = '/WEH/PHP/sekofinale/kategorien.csv';
$liveStateFile = '/WEH/PHP/sekofinale/live_state.json';

$categories = [];

// CSV-Datei laden und alle Kategoriennamen extrahieren
if (file_exists($csvFile) && ($handle = fopen($csvFile, "r")) !== false) {
    $header = fgetcsv($handle, 1000, ";");
    foreach ($header as $col) {
        $categories[] = trim($col);
    }
    fclose($handle);
}

// Aktuelle Live-Daten lesen (wenn vorhanden)
$existing = file_exists($liveStateFile) ? json_decode(file_get_contents($liveStateFile), true) : [];
$usedCategories = $existing['usedCategories'] ?? [];

$input = file_get_contents('php://input');
$data = json_decode($input, true);

$category = $data['category'] ?? '';
$answers = $data['answers'] ?? [];
$players = $data['players'] ?? [];
$currentIndex = $data['currentPlayerIndex'] ?? 0;
$allUsed = $data['allOptionsUsed'] ?? false;

// Kategorie zur Liste der bereits verwendeten hinzufügen
if (!empty($category) && !in_array($category, $usedCategories)) {
    $usedCategories[] = $category;
}

// Live-State aufbauen
$state = [
    'category' => $category,
    'answers' => $answers,
    'players' => $players,
    'currentPlayerIndex' => $currentIndex,
    'allOptionsUsed' => $allUsed,
    'usedCategories' => $usedCategories,
    'allCategories' => ($category === null || $category === '') ? $categories : []
];

// Speichern
file_put_contents($liveStateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
