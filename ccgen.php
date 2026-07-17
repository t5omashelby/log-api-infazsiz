<?php
// ============================================================
// CC GEN API — Luhn Geçerli Kart Üretici
// Kullanım: https://vipcc.onrender.com/gen.php?count=10
// Çıktı: CC|MM|YY|CVV (her satırda bir kart)
// ============================================================
header('Content-Type: text/plain');

function generate_card($bin, $length = 16) {
    $card = $bin;
    while (strlen($card) < $length - 1) {
        $card .= rand(0, 9);
    }
    $sum = 0;
    $alt = true;
    for ($i = strlen($card) - 1; $i >= 0; $i--) {
        $n = (int)$card[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    $checksum = (10 - ($sum % 10)) % 10;
    return $card . $checksum;
}

function generate_random_card() {
    $bins = ['4', '5', '6', '2221', '34', '37', '6011'];
    $bin = $bins[array_rand($bins)];
    $length = ($bin == '34' || $bin == '37') ? 15 : 16;
    $card = generate_card($bin, $length);
    $month = str_pad(rand(1, 12), 2, '0', STR_PAD_LEFT);
    $year = rand(date('y') + 1, date('y') + 5);
    $cvv = ($bin == '34' || $bin == '37') ? rand(1000, 9999) : rand(100, 999);
    return $card . '|' . $month . '|' . $year . '|' . $cvv;
}

$count = isset($_GET['count']) ? intval($_GET['count']) : 10;
if ($count < 1) $count = 1;
if ($count > 1000) $count = 1000;

for ($i = 0; $i < $count; $i++) {
    echo generate_random_card() . "\n";
}
?>