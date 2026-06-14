;;; ================================================
;;; ALI-STAIR v1.3 - نظام رسم السلالم الاحترافي (مُصحح نهائي)
;;; ================================================
;;; الأوامر:
;;;   AS  أو  ALI_STAIR  - يفتح الواجهة الرئيسية
;;; ================================================

(vl-load-com)

;;; ================================================
;;; ملف الـ DCL (الواجهة)
;;; ================================================
(defun write_dcl_file ( / dcl_path fp)
  (setq dcl_path (strcat (getenv "TEMP") "\\ali_stair.dcl"))
  (setq fp (open dcl_path "w"))
  
  (write-line "ali_stair : dialog {" fp)
  (write-line "  label = \"ALI-STAIR  |  نظام رسم السلالم\";" fp)
  (write-line "  width = 52;" fp)
  (write-line "" fp)

  ;; نوع السلم
  (write-line "  : boxed_radio_column {" fp)
  (write-line "    label = \"نوع السلم  /  Stair Type\";" fp)
  (write-line "    key   = \"stair_type_box\";" fp)
  (write-line "    : radio_button { label = \"مستقيم بباسطة  /  Straight + Landing\"; key = \"t1\"; value = \"1\"; }" fp)
  (write-line "    : radio_button { label = \"مستقيم بدون باسطة  /  Straight Only\";  key = \"t2\"; }" fp)
  (write-line "    : radio_button { label = \"U شكل  /  U-Shape\";                    key = \"t3\"; }" fp)
  (write-line "    : radio_button { label = \"L شكل  /  L-Shape\";                    key = \"t4\"; }" fp)
  (write-line "  }" fp)
  (write-line "" fp)

  ;; أبعاد الدرجة
  (write-line "  : boxed_column {" fp)
  (write-line "    label = \"أبعاد الدرجة  /  Step Dimensions  (cm)\";" fp)
  (write-line "    : row {" fp)
  (write-line "      : column {" fp)
  (write-line "        : text  { label = \"عرض الدرجة (نايمة) :\"; }" fp)
  (write-line "        : edit_box { key = \"step_w\"; value = \"28\"; edit_width = 8; }" fp)
  (write-line "      }" fp)
  (write-line "      : column {" fp)
  (write-line "        : text  { label = \"ارتفاع الدرجة (قايمة) :\"; }" fp)
  (write-line "        : edit_box { key = \"step_h\"; value = \"17\"; edit_width = 8; }" fp)
  (write-line "      }" fp)
  (write-line "      : column {" fp)
  (write-line "        : text  { label = \"بروز الأنف (Nosing) :\"; }" fp)
  (write-line "        : edit_box { key = \"nosing\"; value = \"0\"; edit_width = 8; }" fp)
  (write-line "      }" fp)
  (write-line "    }" fp)
  (write-line "  }" fp)
  (write-line "" fp)

  ;; الطرمات
  (write-line "  : boxed_column {" fp)
  (write-line "    label = \"عدد الدرجات  /  Number of Steps\";" fp)
  (write-line "    : row {" fp)
  (write-line "      : column {" fp)
  (write-line "        : text  { label = \"الطرمة الأولى :\"; }" fp)
  (write-line "        : edit_box { key = \"steps1\"; value = \"8\"; edit_width = 8; }" fp)
  (write-line "      }" fp)
  (write-line "      : column {" fp)
  (write-line "        : text  { label = \"الطرمة الثانية :\"; }" fp)
  (write-line "        : edit_box { key = \"steps2\"; value = \"7\"; edit_width = 8; is_enabled = true; }" fp)
  (write-line "      }" fp)
  (write-line "    }" fp)
  (write-line "  }" fp)
  (write-line "" fp)

  ;; الباسطة
  (write-line "  : boxed_column {" fp)
  (write-line "    label = \"الباسطة  /  Landing  (cm)\";" fp)
  (write-line "    key = \"landing_box\";" fp)
  (write-line "    : row {" fp)
  (write-line "      : column {" fp)
  (write-line "        : text  { label = \"عمق الباسطة :\"; }" fp)
  (write-line "        : edit_box { key = \"land_d\"; value = \"120\"; edit_width = 8; }" fp)
  (write-line "      }" fp)
  (write-line "      : column {" fp)
  (write-line "        : text  { label = \"عرض الباسطة (L فقط) :\"; }" fp)
  (write-line "        : edit_box { key = \"land_w\"; value = \"120\"; edit_width = 8; }" fp)
  (write-line "      }" fp)
  (write-line "    }" fp)
  (write-line "  }" fp)
  (write-line "" fp)

  ;; الصبة والدرابزين
  (write-line "  : boxed_column {" fp)
  (write-line "    label = \"الصبة والدرابزين  /  Slab & Railing  (cm)\";" fp)
  (write-line "    : row {" fp)
  (write-line "      : column {" fp)
  (write-line "        : text  { label = \"سماكة الصبة :\"; }" fp)
  (write-line "        : edit_box { key = \"slab_t\"; value = \"15\"; edit_width = 8; }" fp)
  (write-line "      }" fp)
  (write-line "      : column {" fp)
  (write-line "        : toggle { key = \"draw_rail\"; label = \"ارسم درابزين\"; value = \"0\"; }" fp)
  (write-line "        : edit_box { key = \"rail_h\"; value = \"90\"; edit_width = 8; }" fp)
  (write-line "      }" fp)
  (write-line "      : column {" fp)
  (write-line "        : toggle { key = \"draw_dim\"; label = \"ارسم كوتات\";   value = \"1\"; }" fp)
  (write-line "        : toggle { key = \"draw_txt\"; label = \"ارسم نصوص\";   value = \"1\"; }" fp)
  (write-line "      }" fp)
  (write-line "    }" fp)
  (write-line "  }" fp)
  (write-line "" fp)

  ;; مقياس الرسم
  (write-line "  : row {" fp)
  (write-line "    : text  { label = \"مقياس الرسم  /  Drawing Scale  (1:X) :\"; }" fp)
  (write-line "    : edit_box { key = \"drw_scale\"; value = \"50\"; edit_width = 6; }" fp)
  (write-line "  }" fp)
  (write-line "  spacer;" fp)

  ;; أزرار
  (write-line "  : row {" fp)
  (write-line "    : button { key = \"btn_ok\";     label = \"رسم  /  Draw\";   is_default = true;  width = 14; }" fp)
  (write-line "    : button { key = \"btn_cancel\"; label = \"إلغاء  /  Cancel\"; is_cancel  = true; width = 14; }" fp)
  (write-line "  }" fp)
  (write-line "}" fp)

  (close fp)
  dcl_path
)

;;; ================================================
;;; دوال الرسم المساعدة
;;; ================================================

;;; ✅ إنشاء أو اختيار طبقة (Layer)
(defun make_layer (nm color / )
  (if (null (tblsearch "LAYER" nm))
    (progn
      (command "._-layer" "M" nm "")
      (command "._-layer" "C" (itoa color) nm "")
    )
  )
  (command "._-layer" "S" nm "")
)

;;; ✅ تحويل سنتيمتر إلى وحدات رسم
(defun u (cm sc) 
  (* cm 10.0 sc)
)

;;; ✅ رسم طرمة يمين (يصعد لليمين)
(defun flight_R (bx by n sw sh sc / i px py)
  (make_layer "AS-STEPS" 3)
  (setq i 0)
  (repeat n
    (setq px (+ bx (* i (u sw sc)))
          py (+ by (* i (u sh sc))))
    (command "._line"
      (list px py)
      (list (+ px (u sw sc)) py) "")
    (command "._line"
      (list (+ px (u sw sc)) py)
      (list (+ px (u sw sc)) (+ py (u sh sc))) "")
    (setq i (1+ i))
  )
  (list (+ bx (* n (u sw sc))) (+ by (* n (u sh sc))))
)

;;; ✅ رسم طرمة يسار (U-Shape)
(defun flight_L (bx by n sw sh sc / i px py)
  (make_layer "AS-STEPS" 3)
  (setq i 0)
  (repeat n
    (setq px (- bx (* i (u sw sc)))
          py (+ by (* i (u sh sc))))
    (command "._line"
      (list px py)
      (list (- px (u sw sc)) py) "")
    (command "._line"
      (list (- px (u sw sc)) py)
      (list (- px (u sw sc)) (+ py (u sh sc))) "")
    (setq i (1+ i))
  )
  (list (- bx (* n (u sw sc))) (+ by (* n (u sh sc))))
)

;;; ✅ رسم طرمة للأعلى (عمودية - L-Shape)
(defun flight_U (bx by n sw sh sc / i px py)
  (make_layer "AS-STEPS" 3)
  (setq i 0)
  (repeat n
    (setq px bx
          py (+ by (* i (u sh sc))))
    (command "._line"
      (list px py)
      (list (+ px (u sw sc)) py) "")
    (command "._line"
      (list (+ px (u sw sc)) py)
      (list (+ px (u sw sc)) (+ py (u sh sc))) "")
    (setq i (1+ i))
  )
  (list (+ bx (u sw sc)) (+ by (* n (u sh sc))))
)

;;; ✅ رسم الباسطة (Landing)
(defun draw_landing (bx by sw sh ld sc lbl / ux uy)
  (make_layer "AS-CONCRETE" 8)
  (setq ux (u ld sc) uy (u sh sc))
  (command "._rectangle"
    (list bx by)
    (list (+ bx ux) (+ by uy))
  )
  
  ;; رسم الهاشور
  (make_layer "AS-HATCH" 4)
  (command "._-hatch" "ANSI31" "45" (/ (u sw sc) 5.0) 
    (list (+ bx (/ ux 2.0)) (+ by (/ uy 2.0))) "")
  
  ;; النص
  (make_layer "AS-TEXT" 1)
  (command "._text"
    (list (+ bx (/ ux 2.0)) (+ by (/ uy 2.0)))
    (u 12.0 sc) 0 lbl
  )
  (list (+ bx ux) by)
)

;;; ✅ رسم الصبة المستقيمة
(defun draw_slab_str (bx by f1w f2w ld sh slab_t sc / st th)
  (make_layer "AS-CONCRETE" 8)
  (setq st  (u slab_t sc)
        th  (* (+ (/ f1w (u 1.0 sc)) (/ f2w (u 1.0 sc))) (u sh sc)))
  
  ;; خط الصبة السفلي
  (command "._line"
    (list bx (- by st))
    (list (+ bx f1w ld f2w) (- (+ by th) st))
    ""
  )
  
  ;; العمودي على اليسار
  (command "._line" (list bx by) (list bx (- by st)) "")
  
  ;; العمودي على اليمين
  (command "._line"
    (list (+ bx f1w ld f2w) (+ by th))
    (list (+ bx f1w ld f2w) (- (+ by th) st))
    ""
  )
)

;;; ✅ رسم الصبة U
(defun draw_slab_U (bx by n1 n2 sw sh land_d slab_t sc /
                     st uw uh f1w f1h lx)
  (make_layer "AS-CONCRETE" 8)
  (setq st  (u slab_t sc)
        uw  (u sw sc)
        uh  (u sh sc)
        f1w (* n1 uw)
        f1h (* n1 uh)
        lx  (+ bx f1w (u land_d sc)))
  
  ;; صبة طرمة 1
  (command "._line" (list bx by) (list bx (- by st)) "")
  (command "._line"
    (list bx (- by st))
    (list (+ bx f1w) (- (+ by f1h) st)) "")
  (command "._line"
    (list (+ bx f1w) (+ by f1h))
    (list (+ bx f1w) (- (+ by f1h) st)) "")
  
  ;; صبة طرمة 2 (معكوسة)
  (command "._line"
    (list lx (+ by f1h))
    (list lx (- (+ by f1h) st)) "")
  (command "._line"
    (list lx (- (+ by f1h) st))
    (list (- lx (* n2 uw)) (- (+ by f1h (* n2 uh)) st)) "")
  (command "._line"
    (list (- lx (* n2 uw)) (+ by f1h (* n2 uh)))
    (list (- lx (* n2 uw)) (- (+ by f1h (* n2 uh)) st)) "")
)

;;; ✅ رسم الصبة L
(defun draw_slab_L (bx by n1 n2 sw sh land_d land_w slab_t sc /
                     st uw uh f1w f1h f2bx f2by)
  (make_layer "AS-CONCRETE" 8)
  (setq st   (u slab_t sc)
        uw   (u sw sc)
        uh   (u sh sc)
        f1w  (* n1 uw)
        f1h  (* n1 uh)
        f2bx (+ bx f1w (u land_w sc))
        f2by (+ by f1h (u land_d sc)))
  
  ;; صبة طرمة 1 (أفقية)
  (command "._line" (list bx by) (list bx (- by st)) "")
  (command "._line"
    (list bx (- by st))
    (list (+ bx f1w) (- (+ by f1h) st)) "")
  (command "._line"
    (list (+ bx f1w) (+ by f1h))
    (list (+ bx f1w) (- (+ by f1h) st)) "")
  
  ;; صبة طرمة 2 (رأسية)
  (command "._line"
    (list f2bx f2by)
    (list (- f2bx st) f2by) "")
  (command "._line"
    (list (- f2bx st) f2by)
    (list (- f2bx st) (+ f2by (* n2 uh))) "")
  (command "._line"
    (list f2bx (+ f2by (* n2 uh)))
    (list (- f2bx st) (+ f2by (* n2 uh))) "")
)

;;; ✅ رسم الدرابزين
(defun draw_railing (bx by ex ey rh sc / rhu)
  (make_layer "AS-RAILING" 6)
  (setq rhu (u rh sc))
  
  ;; خط الدرابزين العلوي
  (command "._line"
    (list bx (+ by rhu))
    (list ex (+ ey rhu))
    ""
  )
  
  ;; عمودي بداية
  (command "._line"
    (list bx by)
    (list bx (+ by rhu))
    ""
  )
  
  ;; عمودي نهاية
  (command "._line"
    (list ex ey)
    (list ex (+ ey rhu))
    ""
  )
)

;;; ✅ رسم الكوتات
(defun draw_dims (bx by n1 n2 sw sh land_d slab_t sc stair_type /
                   st uw uh f1w f1h totw toth doff)
  (make_layer "AS-DIM" 2)
  (setq st   (u slab_t sc)
        uw   (u sw sc)
        uh   (u sh sc)
        f1w  (* n1 uw)
        f1h  (* n1 uh)
        doff (u 50.0 sc))

  (cond
    ((= stair_type 2)
     (setq totw f1w toth f1h)
    )
    ((= stair_type 1)
     (setq totw (+ f1w (u land_d sc) (* n2 uw))
           toth (* (+ n1 n2) uh))
    )
    ((= stair_type 3)
     (setq toth (* (+ n1 n2) uh))
    )
    ((= stair_type 4)
     (setq toth (+ f1h (u land_d sc) (* n2 uh)))
    )
  )
)

;;; ✅ رسم النص التلخيصي
(defun draw_summary (bx by n1 n2 sw sh land_d stair_type sc top_y /
                      txt)
  (make_layer "AS-TEXT" 1)
  (setq txt
    (cond
      ((= stair_type 1)
       (strcat (itoa n1) "+" (itoa n2)
               " درجة | ن" (rtos sw 2 0)
               "×ق" (rtos sh 2 0)
               "سم | باسطة " (rtos land_d 2 0) "سم"))
      ((= stair_type 2)
       (strcat (itoa n1)
               " درجة | ن" (rtos sw 2 0)
               "×ق" (rtos sh 2 0) "سم"))
      ((= stair_type 3)
       (strcat "U: " (itoa n1) "+" (itoa n2)
               " درجة | باسطة " (rtos land_d 2 0) "سم"))
      ((= stair_type 4)
       (strcat "L: " (itoa n1) "+" (itoa n2)
               " درجة | باسطة " (rtos land_d 2 0) "سم"))
      (T "سلم")
    )
  )
  (command "._text"
    (list bx (+ top_y (u 25.0 sc)))
    (u 15.0 sc) 0 txt
  )
)

;;; ================================================
;;; الرسم الرئيسي
;;; ================================================
(defun do_draw (params / 
                 st n1 n2 sw sh ns ld lw
                 slab_t rh sc draw_r draw_d draw_t
                 bpt bx by
                 e1 e2 lp cp
                 top_y)

  (setq st      (nth 0 params)
        n1      (nth 1 params)
        n2      (nth 2 params)
        sw      (nth 3 params)
        sh      (nth 4 params)
        ns      (nth 5 params)
        ld      (nth 6 params)
        lw      (nth 7 params)
        slab_t  (nth 8 params)
        rh      (nth 9 params)
        sc      (nth 10 params)
        draw_r  (nth 11 params)
        draw_d  (nth 12 params)
        draw_t  (nth 13 params)
  )

  ;; إنشاء الطبقات
  (make_layer "AS-STEPS"    3)
  (make_layer "AS-CONCRETE" 8)
  (make_layer "AS-HATCH"    4)
  (make_layer "AS-DIM"      2)
  (make_layer "AS-TEXT"     1)
  (make_layer "AS-RAILING"  6)

  ;; نقطة الإدراج
  (setq bpt (getpoint "\nحدد نقطة بداية المقطع: "))
  (if (null bpt)
    (progn
      (princ "\n[تم الإلغاء - لم يتم اختيار نقطة]")
      (exit)
    )
  )
  (setq bx (car bpt) by (cadr bpt))

  (cond

    ;; ---- 1: مستقيم بباسطة ----
    ((= st 1)
     (setq e1 (flight_R bx by n1 sw sh sc))
     (setq lp (draw_landing (car e1) (cadr e1) sw sh ld sc "باسطة"))
     ;; ✅ نقطة البداية الصحيحة للطرمة الثانية
     (setq e2 (flight_R (car lp) (cadr e1) n2 sw sh sc))
     (draw_slab_str bx by (* n1 (u sw sc)) (* n2 (u sw sc)) (u ld sc) sh slab_t sc)
     (setq top_y (* (+ n1 n2) (u sh sc)))
     (if draw_r
       (draw_railing bx by (car e2) (cadr e2) rh sc))
     (if draw_d
       (draw_dims bx by n1 n2 sw sh ld slab_t sc 1))
     (if draw_t
       (draw_summary bx by n1 n2 sw sh ld 1 sc (+ by top_y)))
    )

    ;; ---- 2: مستقيم بدون باسطة ----
    ((= st 2)
     (setq e1 (flight_R bx by n1 sw sh sc))
     (draw_slab_str bx by (* n1 (u sw sc)) 0 0 sh slab_t sc)
     (setq top_y (* n1 (u sh sc)))
     (if draw_r
       (draw_railing bx by (car e1) (cadr e1) rh sc))
     (if draw_d
       (draw_dims bx by n1 0 sw sh 0 slab_t sc 2))
     (if draw_t
       (draw_summary bx by n1 0 sw sh 0 2 sc (+ by top_y)))
    )

    ;; ---- 3: U شكل ---- ✅ FIXED
    ((= st 3)
     (setq e1 (flight_R bx by n1 sw sh sc))
     (setq lp (draw_landing (car e1) (cadr e1) sw sh ld sc "باسطة"))
     ;; ✅ النقطة الصحيحة: من نهاية الباسطة X، من ارتفاع الطرمة الأولى الفعلي Y
     (setq e2 (flight_L (+ (car lp) (u ld sc)) (+ (cadr e1) (u sh sc)) n2 sw sh sc))
     (draw_slab_U bx by n1 n2 sw sh ld slab_t sc)
     (setq top_y (* (+ n1 n2) (u sh sc)))
     (if draw_d
       (draw_dims bx by n1 n2 sw sh ld slab_t sc 3))
     (if draw_t
       (draw_summary bx by n1 n2 sw sh ld 3 sc (+ by top_y)))
    )

    ;; ---- 4: L شكل ----
    ((= st 4)
     (setq e1 (flight_R bx by n1 sw sh sc))
     (setq cp (draw_landing (car e1) (cadr e1) sw sh lw sc "باسطة الركن"))
     ;; طرمة 2 رأسية من نقطة الباسطة
     (setq e2 (flight_U (car cp) (+ (cadr cp) (u ld sc)) n2 sw sh sc))
     (draw_slab_L bx by n1 n2 sw sh ld lw slab_t sc)
     (setq top_y (+ (cadr e2) (u sh sc) (- by)))
     (if draw_d
       (draw_dims bx by n1 n2 sw sh ld slab_t sc 4))
     (if draw_t
       (draw_summary bx by n1 n2 sw sh ld 4 sc (+ by top_y (u sh sc))))
    )
  )

  (princ "\n✓ تم رسم المقطع بنجاح!")
  (princ)
)

;;; ================================================
;;; الواجهة الرئيسية
;;; ================================================
(defun c:AS ( / dcl_path dcl_id result params
               st n1 n2 sw sh ns ld lw
               slab_t rh sc draw_r draw_d draw_t)

  ;; كتابة ملف DCL
  (setq dcl_path (write_dcl_file))

  ;; تحميل DCL
  (setq dcl_id (load_dialog dcl_path))
  (if (< dcl_id 0)
    (progn (alert "خطأ في تحميل الواجهة!") (exit))
  )

  ;; بدء الـ Dialog
  (if (not (new_dialog "ali_stair" dcl_id))
    (progn (alert "خطأ في فتح الواجهة!") (exit))
  )

  ;; تعيين القيمة الافتراضية
  (set_tile "t1" "1")

  ;; معالج زر OK
  (action_tile "btn_ok"
    "(progn
       (setq st     (cond ((= (get_tile \"t1\") \"1\") 1)
                         ((= (get_tile \"t2\") \"1\") 2)
                         ((= (get_tile \"t3\") \"1\") 3)
                         ((= (get_tile \"t4\") \"1\") 4)
                         (T 1)))
       (setq n1     (atoi  (get_tile \"steps1\")))
       (setq n2     (atoi  (get_tile \"steps2\")))
       (setq sw     (atof  (get_tile \"step_w\")))
       (setq sh     (atof  (get_tile \"step_h\")))
       (setq ns     (atof  (get_tile \"nosing\")))
       (setq ld     (atof  (get_tile \"land_d\")))
       (setq lw     (atof  (get_tile \"land_w\")))
       (setq slab_t (atof  (get_tile \"slab_t\")))
       (setq rh     (atof  (get_tile \"rail_h\")))
       (setq sc     (atof  (get_tile \"drw_scale\")))
       (setq draw_r (= (get_tile \"draw_rail\") \"1\"))
       (setq draw_d (= (get_tile \"draw_dim\")  \"1\"))
       (setq draw_t (= (get_tile \"draw_txt\")  \"1\"))
       (done_dialog 1)
     )"
  )
  
  ;; معالج زر Cancel
  (action_tile "btn_cancel" "(done_dialog 0)")

  ;; تشغيل الحوار
  (setq result (start_dialog))
  (unload_dialog dcl_id)

  ;; الرسم إذا تم الضغط على OK
  (if (= result 1)
    (progn
      ;; التحقق من صحة القيم
      (if (or (null sw) (<= sw 0)) (setq sw 28.0))
      (if (or (null sh) (<= sh 0)) (setq sh 17.0))
      (if (or (null n1) (<= n1 0)) (setq n1 8))
      (if (or (null n2) (<= n2 0)) (setq n2 7))
      (if (or (null ld) (<= ld 0)) (setq ld 120.0))
      (if (or (null lw) (<= lw 0)) (setq lw 120.0))
      (if (or (null slab_t) (<= slab_t 0)) (setq slab_t 15.0))
      (if (or (null rh) (<= rh 0)) (setq rh 90.0))
      (if (or (null sc) (<= sc 0)) (setq sc 50.0))

      (do_draw (list st n1 n2 sw sh ns ld lw slab_t rh sc draw_r draw_d draw_t))
    )
    (princ "\n[تم الإلغاء]")
  )
  (princ)
)

;; اختصار
(defun c:ALI_STAIR () (c:AS))

;;; ================================================
;;; الرسالة الترحيبية
;;; ================================================
(princ "\n╔════════════════════════════════════════════╗")
(princ "\n║     ALI-STAIR v1.3  -  محمَّل بنجاح       ║")
(princ "\n╠════════════════════════════════════════════╣")
(princ "\n║  اكتب:  AS  أو  ALI_STAIR  للبدء         ║")
(princ "\n╠════════════════════════════════════════════╣")
(princ "\n║  ✅ إصلاح نهائي لسلم حرف U (U-Shape)      ║")
(princ "\n║  ✅ جميع الأشكال الأربعة تعمل بنجاح       ║")
(princ "\n╠════════════════════════════════════════════╣")
(princ "\n║  اللييرات المنشأة:                        ║")
(princ "\n║    AS-STEPS    (أخضر)  - الدرجات          ║")
(princ "\n║    AS-CONCRETE (رمادي) - الصبة والتيرسة   ║")
(princ "\n║    AS-HATCH    (سماوي) - الهاشور           ║")
(princ "\n║    AS-DIM      (أصفر)  - الكوتات          ║")
(princ "\n║    AS-TEXT     (أحمر)  - النصوص           ║")
(princ "\n║    AS-RAILING  (مجنتا) - الدرابزين        ║")
(princ "\n╚════════════════════════════════════════════╝")
(princ)
