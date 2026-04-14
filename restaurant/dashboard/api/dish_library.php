<?php
header('Content-Type: application/json');

$action = $_GET['action'] ?? 'test';
$UNSPLASH_KEY = 'vONbayPb5FFB9hFfuKpB7ZoEwmYVa4krM1wksxhuMeY';

if($action === 'test') {
    $result = array();
    $result['curl_available'] = function_exists('curl_init');
    $result['php_version'] = PHP_VERSION;

    if($result['curl_available']) {
        $ch = curl_init('https://api.unsplash.com/photos/random?client_id=' . $UNSPLASH_KEY);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $result['http_code'] = $code;
        $result['curl_error'] = $err;
        $result['unsplash_ok'] = ($code === 200);
    }

    echo json_encode($result);
    exit;
}

if($action === 'photos') {
    $query_raw = isset($_GET['q']) ? trim($_GET['q']) : 'food';
    $page      = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

    $ar_to_en = array(
        'برغر'=>'burger','بيتزا'=>'pizza','شاورما'=>'shawarma',
        'دجاج'=>'chicken','دجاج مشوي'=>'grilled chicken',
        'سمك'=>'fish','سمك مشوي'=>'grilled fish',
        'باستا'=>'pasta','ستيك'=>'steak','لحم'=>'meat grilled',
        'كباب'=>'kebab','فلافل'=>'falafel','منسف'=>'mansaf lamb',
        'مقلوبة'=>'maqluba rice','بريياني'=>'biryani',
        'تاكو'=>'taco','سوشي'=>'sushi','رامن'=>'ramen',
        'حمص'=>'hummus','تبولة'=>'tabbouleh','فتوش'=>'fattoush',
        'سلطة'=>'salad','شوربة'=>'soup bowl','عدس'=>'lentil soup',
        'بطاطا'=>'french fries','أرز'=>'rice dish','خبز'=>'bread',
        'كيك'=>'cake','آيس كريم'=>'ice cream','كنافة'=>'kunafa',
        'بقلاوة'=>'baklava','تشيز كيك'=>'cheesecake',
        'حلويات'=>'dessert sweet','قهوة'=>'coffee cup','شاي'=>'tea cup',
        'عصير'=>'juice drink','مشروبات'=>'drinks beverage','لبن'=>'yogurt',
        'وجبة'=>'meal food','طعام'=>'food dish','فطور'=>'breakfast',
        'غداء'=>'lunch food','عشاء'=>'dinner food','جبنة'=>'cheese',
        'بيض'=>'eggs breakfast','خضار'=>'vegetables','كشري'=>'koshari',
        'ملوخية'=>'molokhia','بسبوسة'=>'basbousa','مهلبية'=>'milk pudding',
        'مقبلات'=>'appetizer','رئيسية'=>'main dish food',
    );

    $query_lower = mb_strtolower(trim($query_raw));
    $query = isset($ar_to_en[$query_lower]) ? $ar_to_en[$query_lower] : $query_raw;

    // لو لسا عربي — ترجم عبر MyMemory
    if($query === $query_raw && preg_match('/\p{Arabic}/u', $query_raw)) {
        $ch2 = curl_init('https://api.mymemory.translated.net/get?q=' . urlencode($query_raw) . '&langpair=ar|en');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        $tres = curl_exec($ch2);
        curl_close($ch2);
        if($tres) {
            $tdata = json_decode($tres, true);
            $tr = isset($tdata['responseData']['translatedText']) ? $tdata['responseData']['translatedText'] : '';
            if($tr && $tr !== $query_raw) $query = $tr;
        }
    }

    $url = 'https://api.unsplash.com/search/photos?query=' . urlencode($query . ' food') . '&per_page=12&page=' . $page . '&orientation=landscape&client_id=' . $UNSPLASH_KEY;


    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if(!$res || $code !== 200) {
        echo json_encode(array('error' => 'fetch_failed', 'code' => $code, 'photos' => array()));
        exit;
    }

    $data = json_decode($res, true);
    $photos = array();
    foreach($data['results'] as $p) {
        $photos[] = array(
            'id'      => $p['id'],
            'thumb'   => $p['urls']['small'],
            'regular' => $p['urls']['regular'],
            'alt'     => isset($p['alt_description']) ? $p['alt_description'] : '',
            'author'  => isset($p['user']['name'])    ? $p['user']['name']    : '',
        );
    }
    echo json_encode(array('photos' => $photos, 'total' => isset($data['total']) ? $data['total'] : 0));
    exit;
}

if($action === 'download') {
    $img_url = isset($_POST['url']) ? trim($_POST['url']) : '';
    if(!$img_url) {
        echo json_encode(array('error' => 'no_url'));
        exit;
    }

    $upload_dir = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/assets/uploads/dishes/';
    if(!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $ch = curl_init($img_url . '&w=800&q=80');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $img_data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if(!$img_data || strlen($img_data) < 1000) {
        echo json_encode(array('error' => 'download_failed', 'code' => $http_code));
        exit;
    }

    $filename = 'lib_' . uniqid() . '.jpg';
    if(file_put_contents($upload_dir . $filename, $img_data)) {
        echo json_encode(array('success' => true, 'filename' => $filename));
    } else {
        echo json_encode(array('error' => 'save_failed'));
    }
    exit;
}

echo json_encode(array('error' => 'unknown_action'));