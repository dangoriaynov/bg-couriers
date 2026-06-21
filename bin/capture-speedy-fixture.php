<?php
// Usage: SPEEDY_USER=.. SPEEDY_PASS=.. php bin/capture-speedy-fixture.php
$base = 'https://api.speedy.bg/v1';
$auth = ['userName' => getenv('SPEEDY_USER'), 'password' => getenv('SPEEDY_PASS'), 'language' => 'EN'];
$call = function ($path, $body) use ($base) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_POST => 1, CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
    return curl_exec($ch);
};
file_put_contents(__DIR__ . '/../tests/fixtures/speedy/find_site.json',
    $call('/location/site', $auth + ['countryId' => 100, 'name' => 'Dobrich']));
file_put_contents(__DIR__ . '/../tests/fixtures/speedy/find_office.json',
    $call('/location/office', $auth + ['countryId' => 100, 'name' => 'Dobrich']));
echo "Saved fixtures.\n";
