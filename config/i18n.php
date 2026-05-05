<?php
// i18n.php — ملف الترجمات المركزي
// الاستخدام: t('key') أو t('key', 'en')

function t($key, $lang = null) {
    global $current_lang;
    $l = $lang ?? $current_lang ?? 'ar';
    $translations = get_translations();
    if($l === 'en' && isset($translations[$key]['en'])) return $translations[$key]['en'];
    return $translations[$key]['ar'] ?? $key;
}

function get_translations() {
    return [
        // ===== عام =====
        'menu'              => ['ar' => 'المنيو',           'en' => 'Menu'],
        'cart'              => ['ar' => 'سلة الطلبات',      'en' => 'Cart'],
        'order'             => ['ar' => 'الطلب',            'en' => 'Order'],
        'total'             => ['ar' => 'الإجمالي',         'en' => 'Total'],
        'subtotal'          => ['ar' => 'المجموع الفرعي',   'en' => 'Subtotal'],
        'discount'          => ['ar' => 'خصم الكوبون',      'en' => 'Coupon Discount'],
        'add_to_cart'       => ['ar' => 'أضف للسلة',        'en' => 'Add to Cart'],
        'added'             => ['ar' => 'تمت الإضافة!',     'en' => 'Added!'],
        'back'              => ['ar' => 'رجوع',              'en' => 'Back'],
        'save'              => ['ar' => 'حفظ',               'en' => 'Save'],
        'cancel'            => ['ar' => 'إلغاء',             'en' => 'Cancel'],
        'search'            => ['ar' => 'ابحث عن طبق...',   'en' => 'Search for a dish...'],
        'no_results'        => ['ar' => 'لا توجد نتائج لبحثك','en' => 'No results found'],
        'all'               => ['ar' => 'الكل',              'en' => 'All'],
        'open'              => ['ar' => 'نقبل الطلبات',      'en' => 'Open Now'],
        'items'             => ['ar' => 'صنف',               'en' => 'items'],
        'powered_by'        => ['ar' => 'Powered by MenuPro','en' => 'Powered by MenuPro'],
        'rights'            => ['ar' => 'جميع الحقوق محفوظة','en' => 'All rights reserved'],
        'table'             => ['ar' => 'طاولة',             'en' => 'Table'],
        'view_menu'         => ['ar' => 'تصفح المنيو',       'en' => 'Browse Menu'],

        // ===== المنيو الرئيسي =====
        'sold_out'          => ['ar' => 'نفذ اليوم',         'en' => 'Sold Out'],
        'ingredients'       => ['ar' => 'المكونات',          'en' => 'Ingredients'],
        'recipe'            => ['ar' => 'طريقة التحضير',     'en' => 'Preparation'],
        'ar_available'      => ['ar' => 'AR متاح',           'en' => 'AR Available'],
        'ratings_title'     => ['ar' => 'تقييمات الزبائن',   'en' => 'Customer Reviews'],
        'no_ratings'        => ['ar' => 'كن أول من يقيّم هذا الطبق!', 'en' => 'Be the first to review!'],
        'qty'               => ['ar' => 'الكمية',            'en' => 'Qty'],
        'empty_menu'        => ['ar' => 'قيد التحضير، تفضل لاحقاً!', 'en' => 'Coming soon, check back later!'],
        'reviews'           => ['ar' => 'تقييم',             'en' => 'reviews'],
        'view_3d'           => ['ar' => 'ثلاثي الأبعاد',     'en' => '3D View'],
        'photo'             => ['ar' => 'صورة',              'en' => 'Photo'],
        'ar_ios'            => ['ar' => 'AR على iPhone',     'en' => 'AR on iPhone'],
        'ar_android'        => ['ar' => 'AR على Android',    'en' => 'AR on Android'],

        // ===== السلة =====
        'cart_title'        => ['ar' => 'سلة الطلبات',       'en' => 'Your Cart'],
        'cart_empty'        => ['ar' => 'سلتك فارغة!',       'en' => 'Your cart is empty!'],
        'items_selected'    => ['ar' => 'الأصناف المختارة',  'en' => 'Selected Items'],
        'order_details'     => ['ar' => 'بيانات الطلب',      'en' => 'Order Details'],
        'table_number'      => ['ar' => 'رقم الطاولة *',     'en' => 'Table Number *'],
        'table_required'    => ['ar' => '⚠️ رقم الطاولة مطلوب للطلب', 'en' => '⚠️ Table number is required'],
        'your_name'         => ['ar' => 'اسمك (اختياري)',    'en' => 'Your name (optional)'],
        'notes'             => ['ar' => 'ملاحظات إضافية...', 'en' => 'Additional notes...'],
        'coupon'            => ['ar' => 'كوبون الخصم',       'en' => 'Discount Coupon'],
        'coupon_placeholder'=> ['ar' => 'كود الخصم...',      'en' => 'Coupon code...'],
        'apply'             => ['ar' => 'تطبيق',             'en' => 'Apply'],
        'order_summary'     => ['ar' => 'ملخص الطلب',        'en' => 'Order Summary'],
        'confirm_order'     => ['ar' => 'تأكيد الطلب',       'en' => 'Place Order'],
        'sending'           => ['ar' => 'جاري إرسال الطلب...','en' => 'Sending order...'],
        'qr_auto'           => ['ar' => 'تم تحديده تلقائياً من QR Code', 'en' => 'Auto-detected from QR Code'],
        'no_thanks'         => ['ar' => 'لا شكراً، السلة كافية', 'en' => 'No thanks, continue'],
        'you_may_like'      => ['ar' => 'قد يعجبك أيضاً',    'en' => 'You might also like'],
        'others_ordered'    => ['ar' => 'زبائن طلبوا هذه الأطباق مع طلبك', 'en' => 'Customers ordered these with their order'],

        // ===== التتبع =====
        'track_order'       => ['ar' => 'تتبع طلب',          'en' => 'Track Order'],
        'order_received'    => ['ar' => 'استلمنا طلبك',       'en' => 'Order Received'],
        'order_confirmed'   => ['ar' => 'تم تأكيد طلبك',     'en' => 'Order Confirmed'],
        'preparing'         => ['ar' => 'جاري التحضير',       'en' => 'Preparing'],
        'ready'             => ['ar' => 'طلبك جاهز!',         'en' => 'Ready!'],
        'delivered'         => ['ar' => 'تم التسليم',          'en' => 'Delivered'],
        'cancelled'         => ['ar' => 'تم إلغاء الطلب',     'en' => 'Order Cancelled'],
        'cancelled_sub'     => ['ar' => 'يمكنك تقديم طلب جديد من المنيو', 'en' => 'You can place a new order from the menu'],
        'your_order_stage'  => ['ar' => 'مرحلة طلبك',         'en' => 'Order Status'],
        'order_details2'    => ['ar' => 'تفاصيل الطلب',       'en' => 'Order Details'],
        'items_ordered'     => ['ar' => 'الأصناف المطلوبة',   'en' => 'Items Ordered'],
        'unit_price'        => ['ar' => '/ الوحدة',           'en' => '/ unit'],
        'auto_refresh'      => ['ar' => 'يتحدث تلقائياً كل 10 ثوانٍ', 'en' => 'Auto-updates every 10 seconds'],
        'new_order'         => ['ar' => 'طلب جديد',           'en' => 'New Order'],
        'view_invoice'      => ['ar' => 'عرض الفاتورة',       'en' => 'View Invoice'],
        'rate_experience'   => ['ar' => 'قيّم تجربتك واحصل على الفاتورة', 'en' => 'Rate your experience & get invoice'],
        'now'               => ['ar' => 'الآن',               'en' => 'Now'],

        // ===== الفاتورة =====
        'invoice'           => ['ar' => 'فاتورة',             'en' => 'Invoice'],
        'invoice_date'      => ['ar' => 'التاريخ',            'en' => 'Date'],
        'payment_method'    => ['ar' => 'طريقة الدفع',        'en' => 'Payment Method'],
        'cash'              => ['ar' => 'دفع نقدي',           'en' => 'Cash Payment'],
        'cash_sub'          => ['ar' => 'ادفع للنادل مباشرة', 'en' => 'Pay the waiter directly'],
        'shamcash'          => ['ar' => 'شام كاش',            'en' => 'ShamCash'],
        'shamcash_sub'      => ['ar' => 'دفع إلكتروني فوري',  'en' => 'Instant digital payment'],
        'send_amount'       => ['ar' => 'ارسل المبلغ لهذا الحساب', 'en' => 'Send amount to this account'],
        'account_number'    => ['ar' => 'رقم الحساب',         'en' => 'Account Number'],
        'copy'              => ['ar' => 'نسخ',                'en' => 'Copy'],
        'copied'            => ['ar' => '✅ تم',              'en' => '✅ Copied'],
        'scan_qr'           => ['ar' => 'امسح QR من تطبيق شام كاش', 'en' => 'Scan QR with ShamCash app'],
        'thank_you'         => ['ar' => 'شكراً لزيارتك!',    'en' => 'Thank you for visiting!'],
        'thank_you_sub'     => ['ar' => 'نتمنى أن تكون تجربتك ممتازة', 'en' => 'We hope you had a great experience'],
        'see_you_soon'      => ['ar' => 'نراك قريباً في',     'en' => 'See you soon at'],
        'print_invoice'     => ['ar' => 'طباعة الفاتورة',     'en' => 'Print Invoice'],
        'coupon_used'       => ['ar' => 'كوبون',              'en' => 'Coupon'],

        // ===== التقييم =====
        'rate_title'        => ['ar' => 'كيف كانت تجربتك؟',  'en' => 'How was your experience?'],
        'rate_sub'          => ['ar' => 'تقييمك يساعدنا نتحسن لك في المرة القادمة', 'en' => 'Your feedback helps us improve'],
        'rate_overall'      => ['ar' => 'تقييمك العام للطلب', 'en' => 'Overall Rating'],
        'fav_dish'          => ['ar' => 'أكثر طبق أعجبك',    'en' => 'Your Favorite Dish'],
        'optional'          => ['ar' => 'اختياري',            'en' => 'Optional'],
        'comment_ph'        => ['ar' => 'أضف تعليقاً على تجربتك... (اختياري)', 'en' => 'Add a comment about your experience... (optional)'],
        'skip'              => ['ar' => 'تخطي',               'en' => 'Skip'],
        'send_rating'       => ['ar' => 'أرسل التقييم',       'en' => 'Submit Rating'],
        'sending_rating'    => ['ar' => 'جاري الإرسال...',    'en' => 'Submitting...'],
        'hint_1'            => ['ar' => 'سيئ 😞',             'en' => 'Bad 😞'],
        'hint_2'            => ['ar' => 'مقبول 😐',           'en' => 'Fair 😐'],
        'hint_3'            => ['ar' => 'جيد 👍',             'en' => 'Good 👍'],
        'hint_4'            => ['ar' => 'ممتاز 😊',           'en' => 'Great 😊'],
        'hint_5'            => ['ar' => 'رائع! 🌟',           'en' => 'Amazing! 🌟'],
        'table_badge'       => ['ar' => 'طاولة',              'en' => 'Table'],
        'items_title'       => ['ar' => 'الأصناف المطلوبة',   'en' => 'Items Ordered'],
    ];
}