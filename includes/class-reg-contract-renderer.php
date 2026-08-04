<?php
/**
 * Renders and freezes versioned agreement templates.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Olama_Reg_Contract_Renderer {

    public static function default_registration_content(): string {
        return <<<'HTML'
<h1 style="text-align:center">عقد تسجيل طالب في قسم الروضة</h1>
<h2 style="text-align:center">{{academy.legal_name}}</h2>
<p style="text-align:center"><strong>للعام الدراسي {{academic_year}}</strong><br>رقم العقد: {{contract.number}}</p>

<p>حرر هذا العقد بتاريخ <strong>{{contract.date}}</strong> بين كل من:</p>

<h2>أولاً: الأكاديمية</h2>
<p><strong>{{academy.legal_name}}</strong>، مؤسسة تعليمية مرخصة، وعنوانها {{academy.address}}، ويشار إليها لاحقاً بـ «الأكاديمية».</p>

<h2>ثانياً: ولي الأمر</h2>
<table>
<tr><th>الاسم</th><td>{{guardian.full_name}}</td><th>الرقم الوطني</th><td>{{guardian.national_id}}</td></tr>
<tr><th>صلة القرابة</th><td>{{guardian.relationship}}</td><th>الهاتف</th><td>{{guardian.primary_phone}}</td></tr>
<tr><th>العنوان</th><td colspan="3">{{guardian.address}}</td></tr>
</table>

<h2>بيانات الطالب</h2>
<table>
<tr><th>اسم الطالب</th><td>{{student.full_name}}</td><th>الرقم الوطني</th><td>{{student.national_id}}</td></tr>
<tr><th>تاريخ الميلاد</th><td>{{student.birth_date}}</td><th>الجنس</th><td>{{student.gender}}</td></tr>
<tr><th>المستوى</th><td>{{student.grade}}</td><th>الشعبة</th><td>{{student.section}}</td></tr>
</table>

<h2>المادة الأولى: موضوع العقد ومدته</h2>
<ol>
<li>تسجل الأكاديمية الطالب في قسم الروضة خلال العام الدراسي {{academic_year}}، من {{contract.start_date}} وحتى {{contract.end_date}}.</li>
<li>تقدم الأكاديمية الخدمات التعليمية والتربوية والرعائية المعتمدة وفق برنامجها وتقويمها وتعليمات الجهات الرسمية المختصة.</li>
<li>لا يشمل العقد أي خدمة إضافية إلا إذا أدرجت صراحة في الرسوم أو الملاحق.</li>
</ol>

<h2>المادة الثانية: صحة المعلومات والوثائق</h2>
<ol>
<li>يقر ولي الأمر بأن المعلومات والوثائق المقدمة صحيحة وكاملة وحديثة.</li>
<li>يلتزم ولي الأمر بتقديم الوثائق المطلوبة وإبلاغ الأكاديمية فوراً عن أي تغيير في بيانات الاتصال أو العنوان أو الحالة الصحية أو الأشخاص المخولين بالاستلام.</li>
<li>يجوز تعليق الإجراءات التي تعتمد على معلومات ناقصة أو غير صحيحة إلى حين استكمالها، بما يحفظ سلامة الطالب وحقوق الأطراف.</li>
</ol>

<h2>المادة الثالثة: الصحة والأدوية والطوارئ</h2>
<ol>
<li>يلتزم ولي الأمر بالإفصاح عن الحالات الصحية أو النفسية أو السلوكية أو النمائية المؤثرة في سلامة الطالب أو الرعاية المطلوبة.</li>
<li>لا يعطى الطالب دواء إلا بطلب خطي وتعليمات واضحة وبعد تسليمه مباشرة إلى الموظف المخول بعبوته الأصلية.</li>
<li>في الحالات الطارئة تفوض الأكاديمية باتخاذ إجراءات الإسعاف الضرورية والاتصال بولي الأمر أو الجهات الطبية المختصة.</li>
<li>لا يعفي هذا التفويض الأكاديمية من مسؤوليتها القانونية عن الخطأ أو الإهمال المثبت وفق الأصول.</li>
</ol>

<h2>المادة الرابعة: الحضور والاستلام والسلامة</h2>
<ol>
<li>يلتزم ولي الأمر بمواعيد الحضور والانصراف وتعليمات الاستلام والتسليم.</li>
<li>لا يسلم الطالب إلا لولي الأمر أو لشخص مخول، وبعد التحقق من هويته وفق إجراءات الأكاديمية.</li>
<li>يجب إبلاغ الأكاديمية عن غياب الطالب، ولا يؤدي الغياب وحده إلى تخفيض الرسوم إلا بقرار خطي أو وفق التشريعات النافذة.</li>
</ol>

<h2>المادة الخامسة: التعليمات والسلوك</h2>
<ol>
<li>يلتزم ولي الأمر والطالب بالتعليمات الإدارية والتعليمية والصحية والأمنية واحترام العاملين والأطفال وخصوصيتهم.</li>
<li>يمنع تصوير الأطفال الآخرين أو نشر معلوماتهم دون موافقة أصحاب العلاقة.</li>
<li>عند ظهور سلوك يعرض الطالب أو الآخرين للخطر، تتعاون الأكاديمية وولي الأمر في إعداد خطة متابعة مناسبة.</li>
</ol>

<h2>المادة السادسة: الممتلكات والمستلزمات</h2>
<ol>
<li>يوفر ولي الأمر المستلزمات الشخصية والتعليمية ما لم تكن مشمولة صراحة ضمن الرسوم.</li>
<li>عند إتلاف ممتلكات الأكاديمية عمداً أو نتيجة إهمال جسيم، يجوز المطالبة بتكلفة الإصلاح أو الاستبدال الفعلية والمعقولة بعد التحقق وإبلاغ ولي الأمر.</li>
</ol>

<h2>المادة السابعة: الرسوم والدفعات</h2>
<ol>
<li>يلتزم ولي الأمر بالرسوم وجدول الدفعات المبينين أدناه.</li>
<li>لا يعتد بأي خصم أو تأجيل أو تعديل إلا إذا كان مسجلاً ومعتمداً من الإدارة المخولة.</li>
<li>عند التأخر ترسل الأكاديمية إشعاراً وتمنح ولي الأمر مهلة مقدارها {{policy.payment_grace_days}} أيام قبل اتخاذ الإجراءات التي يسمح بها القانون.</li>
</ol>
{{component.fee_table}}
{{component.installment_schedule}}

<h2>المادة الثامنة: الانسحاب وإنهاء التسجيل</h2>
<ol>
<li>يقدم طلب الانسحاب خطياً مع تحديد آخر يوم دوام مطلوب.</li>
<li>تسوى الرسوم وفق الخدمات المقدمة وجدول الرسوم وشروط الخصم والتشريعات النافذة.</li>
<li>يجوز إنهاء التسجيل بعد الإشعار ومنح فرصة مناسبة للمعالجة متى أمكن، عند تقديم معلومات جوهرية غير صحيحة أو عدم السداد أو مخالفة تعليمات السلامة أو تعذر توفير رعاية متخصصة تتجاوز إمكانات الأكاديمية.</li>
</ol>

{{#if services.transportation}}
<h2>ملحق خدمة النقل</h2>
<ol>
<li>خدمة النقل خدمة إضافية تقدم وفق المسارات والسعة ومتطلبات السلامة.</li>
<li>بيانات الاشتراك الحالية: {{transportation.summary}}</li>
<li>أوقات الوصول تقديرية، ويجوز تعديل المسار أو نقطة التجمع لأسباب تشغيلية أو متطلبات السلامة بعد الإشعار متى أمكن.</li>
<li>عند إيقاف الأكاديمية للخدمة نهائياً دون إخلال من ولي الأمر، تسوى رسوم المدة التي لم تقدم عنها الخدمة.</li>
</ol>
{{/if}}

<h2>المادة التاسعة: الرحلات والأنشطة</h2>
<ol>
<li>لا يشارك الطالب في نشاط خارجي يحتاج إلى موافقة إلا بعد تسجيل موافقة ولي الأمر.</li>
<li>يجوز فرض رسوم منفصلة للأنشطة الاختيارية بعد إعلان تفاصيلها مسبقاً.</li>
</ol>

<h2>المادة العاشرة: التصوير والنشر</h2>
{{component.photo_consent}}

<h2>المادة الحادية عشرة: الخصوصية والتواصل</h2>
<ol>
<li>تستخدم بيانات ولي الأمر والطالب لأغراض التسجيل والتعليم والرعاية والنقل والتواصل والالتزامات الرسمية.</li>
<li>تعالج البيانات الصحية والشخصية الحساسة وفق الموافقات المسجلة، ولا يطلع عليها إلا الأشخاص المخولون.</li>
<li>تعتمد وسائل الاتصال المسجلة لإرسال الإشعارات، ويلتزم ولي الأمر بتحديثها.</li>
</ol>

<h2>المادة الثانية عشرة: الظروف الطارئة</h2>
<p>يجوز تعديل الدوام أو البرنامج أو طريقة تقديم الخدمة بسبب القرارات الرسمية أو الطقس الخطير أو الأوبئة أو الظروف الأمنية أو القوة القاهرة، وتعالج الآثار وفق التعليمات الرسمية والخدمات المقدمة فعلياً.</p>

<h2>المادة الثالثة عشرة: أحكام عامة</h2>
<ol>
<li>تعتبر بيانات التسجيل والملاحق المالية والصحية وموافقات ولي الأمر أجزاء لا تتجزأ من العقد.</li>
<li>لا يعدل العقد إلا بملحق مكتوب ومعتمد أو وفق إجراء تنظيمي لا ينتقص من الحقوق الأساسية للأطراف.</li>
<li>تطبق القوانين والأنظمة والتعليمات النافذة في المملكة الأردنية الهاشمية.</li>
<li>يسعى الطرفان إلى حل الخلاف ودياً، وفي حال تعذر ذلك تكون الجهات القضائية الأردنية المختصة صاحبة الاختصاص.</li>
</ol>

<h2>إقرار ولي الأمر</h2>
<p>أقر بأنني قرأت العقد وملاحقه وفهمتها، وراجعت البيانات والرسوم والدفعات، وقدمت الإفصاحات المطلوبة بصورة صحيحة وكاملة.</p>

<div class="contract-signatures">
<div><strong>الأكاديمية</strong><br><br>الاسم: __________________<br>التوقيع والختم: __________________<br>التاريخ: __________________</div>
<div><strong>ولي الأمر</strong><br><br>الاسم: {{guardian.full_name}}<br>التوقيع: __________________<br>التاريخ: __________________</div>
</div>
HTML;
    }

    public static function render( object $agreement, ?object $template = null ): string|\WP_Error {
        $template = $template ?: Olama_Reg_Agreement_Templates::get( (int) ( $agreement->template_id ?? 0 ) );
        if ( ! $template || empty( $template->contract_content ) ) {
            return new \WP_Error( 'missing_contract_template', __( 'يجب اختيار نموذج عقد صالح.', 'olama-registration' ) );
        }

        $context    = self::build_context( $agreement );
        $content    = (string) $template->contract_content;
        $conditions = [
            'services.transportation' => ! empty( $context['services']['transportation'] ),
        ];

        $content = preg_replace_callback(
            '/\{\{#if\s+([a-z0-9_.-]+)\}\}(.*?)\{\{\/if\}\}/si',
            static function ( array $match ) use ( $conditions ): string {
                return ! empty( $conditions[ $match[1] ] ) ? $match[2] : '';
            },
            $content
        );

        foreach ( $context['components'] as $key => $html ) {
            $content = str_replace( '{{component.' . $key . '}}', $html, $content );
        }

        $flat = self::flatten( $context );
        $content = preg_replace_callback(
            '/\{\{([a-z0-9_.-]+)\}\}/i',
            static function ( array $match ) use ( $flat ): string {
                return esc_html( (string) ( $flat[ $match[1] ] ?? '—' ) );
            },
            $content
        );

        return wp_kses_post( $content );
    }

    public static function snapshot( int $agreement_id ): true|\WP_Error {
        global $wpdb;

        $agreement = Olama_Reg_Agreement::get( $agreement_id );
        if ( ! $agreement ) {
            return new \WP_Error( 'agreement_not_found', __( 'العقد غير موجود.', 'olama-registration' ) );
        }

        $template = Olama_Reg_Agreement_Templates::get( (int) $agreement->template_id );
        $rendered = self::render( $agreement, $template );
        if ( is_wp_error( $rendered ) ) {
            return $rendered;
        }

        $variables = self::build_context( $agreement );
        unset( $variables['components'] );
        $generated_at = current_time( 'mysql' );
        $hash = hash( 'sha256', $rendered . '|' . wp_json_encode( $variables ) . '|' . (int) $template->version );

        $updated = $wpdb->update(
            $wpdb->prefix . 'olama_agreements',
            [
                'template_version'           => (int) $template->version,
                'contract_snapshot'          => $rendered,
                'contract_variables_snapshot'=> wp_json_encode( $variables, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'contract_hash'              => $hash,
                'contract_generated_at'      => $generated_at,
            ],
            [ 'id' => $agreement_id ],
            [ '%d', '%s', '%s', '%s', '%s' ],
            [ '%d' ]
        );

        return $updated === false
            ? new \WP_Error( 'contract_snapshot_failed', __( 'تعذر حفظ النسخة النهائية للعقد.', 'olama-registration' ) )
            : true;
    }

    private static function build_context( object $agreement ): array {
        $family = null;
        $student = null;
        $guardian_name = (string) ( $agreement->payer_name ?? '' );
        $guardian_phone = '';
        $guardian_address = '';
        $guardian_national_id = '';

        if ( $agreement->payer_type === 'family' ) {
            $family = Olama_Reg_Core_Gateway::family(
                (string) ( $agreement->family_uid ?? $agreement->payer_ref ?? $agreement->payer_id )
            );
            if ( $family ) {
                $guardian_name = $family->display_name;
                $guardian_phone = self::value( $family, [ 'primary_mobile', 'father_mobile', 'mother_mobile', 'display_phone' ] );
                $guardian_address = self::value( $family, [ 'family_address', 'address', 'display_address' ] );
                $guardian_national_id = self::value( $family, [ 'sponsor_national_no', 'father_national_no', 'mother_national_no' ] );
            }

            $student_uids = array_values( array_filter( array_map(
                'strval',
                (array) ( $agreement->participant_ids_array ?? [] )
            ) ) );

            $family_reference = (string) (
                $agreement->family_uid
                ?? $agreement->payer_ref
                ?? $agreement->payer_id
            );
            $family_students = Olama_Reg_Core_Gateway::students_for_family(
                $family_reference,
                (int) ( $agreement->academic_year_id ?? 0 )
            );

            $students = [];
            if ( $student_uids ) {
                foreach ( $student_uids as $uid ) {
                    $st = null;
                    foreach ( $family_students as $family_student ) {
                        if ( (string) $family_student->student_uid === (string) $uid ) {
                            $st = $family_student;
                            break;
                        }
                    }
                    if ( ! $st ) {
                        $st = Olama_Reg_Core_Gateway::student( (string) $uid );
                    }
                    if ( $st ) {
                        $students[] = $st;
                    }
                }
            }

            // A newly created family agreement may not have its fee rows assigned
            // yet. When Core has exactly one student for the family, that student
            // is unambiguous and can safely populate the draft contract.
            if ( empty( $students ) && count( $family_students ) === 1 ) {
                $students = [ $family_students[0] ];
            }
            $student = $students[0] ?? null;
        } else {
            $customer = Olama_Reg_Customer::get( (int) ( $agreement->customer_id ?? $agreement->payer_id ) );
            if ( $customer ) {
                $guardian_name = (string) $customer->customer_name;
                $guardian_phone = (string) ( $customer->phone ?? '' );
                $guardian_address = (string) ( $customer->address ?? '' );
                $guardian_national_id = (string) ( $customer->national_id ?? '' );
            }
            $participant_ids = array_values( array_filter( array_map(
                'intval',
                (array) ( $agreement->participant_ids_array ?? [] )
            ) ) );
            if ( empty( $participant_ids ) && ! empty( $agreement->participant_id ) ) {
                $participant_ids = [ (int) $agreement->participant_id ];
            }

            $students = [];
            foreach ( $participant_ids as $pid ) {
                $child = Olama_Reg_Child::get( $pid );
                if ( $child ) {
                    $students[] = (object) [
                        'student_name'       => $child->child_name ?? '',
                        'student_national_no'=> $child->national_id ?? '',
                        'birth_date'         => $child->birth_date ?? '',
                        'gender'             => $child->gender ?? '',
                        'grade_name'         => $child->grade ?? '',
                        'section_name'       => '',
                    ];
                }
            }
            $student = $students[0] ?? null;
        }

        $transportation = [];
        if ( $agreement->payer_type === 'family' && ! empty( $agreement->academic_year_id ) ) {
            $transportation = Olama_Reg_Core_Gateway::transportation(
                (string) ( $agreement->family_uid ?? $agreement->payer_id ),
                (int) $agreement->academic_year_id
            );
        }
        $has_transport_fee = self::has_transport_fee( (int) $agreement->id );
        $has_transport = ! empty( $transportation ) || $has_transport_fee;

        $settings = (array) get_option( 'olama_school_settings', [] );
        $year = Olama_Reg_Academic_Year_Context::get( (int) ( $agreement->academic_year_id ?? 0 ) );

        return [
            'academy' => [
                'legal_name' => (string) ( $settings['school_name_ar'] ?? 'أكاديمية علماء المستقبل' ),
                'address'    => (string) ( $settings['address'] ?? $settings['school_address'] ?? '—' ),
            ],
            'academic_year' => $year ? $year->canonical_code : (string) ( $agreement->study_year ?? '' ),
            'contract' => [
                'number'     => (string) $agreement->agreement_number,
                'date'       => mysql2date( 'Y-m-d', (string) $agreement->created_at ),
                'start_date' => (string) $agreement->start_date,
                'end_date'   => (string) $agreement->end_date,
            ],
            'guardian' => [
                'full_name'    => $guardian_name,
                'national_id'  => $guardian_national_id,
                'relationship' => __( 'ولي الأمر', 'olama-registration' ),
                'primary_phone'=> $guardian_phone,
                'address'      => $guardian_address,
            ],
            'student' => [
                'full_name'   => self::values_joined( $students, [ 'student_name', 'display_name', 'child_name' ] ),
                'national_id' => self::values_joined( $students, [ 'student_national_no', 'national_id' ] ),
                'birth_date'  => self::values_joined( $students, [ 'student_birth_date', 'birth_date', 'date_of_birth' ] ),
                'gender'      => self::values_joined( $students, [ 'student_gender_name', 'gender', 'student_gender' ] ),
                'grade'       => self::values_joined( $students, [ 'class_name', 'grade_name', 'grade' ] ),
                'section'     => self::values_joined( $students, [ 'section_name' ] ),
            ],
            'policy' => [
                'payment_grace_days' => (int) get_option( 'olama_reg_payment_grace_days', 7 ),
            ],
            'services' => [
                'transportation' => $has_transport,
            ],
            'transportation' => [
                'summary' => $has_transport ? self::transportation_summary( $transportation ) : '',
            ],
            'components' => [
                'fee_table'            => self::fee_table( (int) $agreement->id ),
                'installment_schedule' => self::installment_table( (int) $agreement->id ),
                'photo_consent'        => self::photo_consent(),
            ],
        ];
    }

    private static function fee_table( int $agreement_id ): string {
        $fees = Olama_Reg_Agreement_Fees::get_by_agreement( $agreement_id );
        $html = '<h3>الملحق المالي: الرسوم</h3><table><thead><tr><th>البيان</th><th>المبلغ</th><th>الخصم</th><th>الصافي</th><th>الاستحقاق</th></tr></thead><tbody>';
        $subtotal = $discount = $total = 0.0;
        foreach ( $fees as $fee ) {
            $subtotal += (float) $fee->amount;
            $discount += (float) $fee->discount;
            $total += (float) $fee->net_amount;

            $description = '<strong>' . esc_html( $fee->label ) . '</strong>';
            $amount_html = '<strong>' . esc_html( number_format( (float) $fee->amount, 3 ) ) . '</strong>';
            if ( is_numeric( $fee->fee_category ) && (int) $fee->fee_category > 0 ) {
                $template = Olama_Reg_Billing_Fees::get_template( (int) $fee->fee_category );
                if ( $template && ! empty( $template->items ) ) {
                    $amount_html .= '<div class="contract-fee-amount-details"><em>تفاصيل المبلغ</em>';
                    foreach ( $template->items as $item ) {
                        $amount_html .= '<div><span>' . esc_html( $item['description'] ?? '' ) . '</span><b>' .
                            esc_html( number_format( (float) ( $item['amount'] ?? 0 ), 3 ) ) .
                            ' د.أ</b></div>';
                    }
                    $amount_html .= '</div>';
                }
            }

            $due_date = (string) ( $fee->due_date ?? '' );
            if ( $due_date === '' || $due_date === '0000-00-00' ) {
                $due_date = '—';
            }

            $html .= '<tr><td>' . $description . '</td><td class="contract-fee-amount">' . $amount_html . '</td><td>' . number_format( (float) $fee->discount, 3 ) . '</td><td>' . number_format( (float) $fee->net_amount, 3 ) . '</td><td>' . esc_html( $due_date ) . '</td></tr>';
        }
        $html .= '</tbody><tfoot><tr><th>الإجمالي</th><th>' . number_format( $subtotal, 3 ) . '</th><th>' . number_format( $discount, 3 ) . '</th><th>' . number_format( $total, 3 ) . ' د.أ</th><th></th></tr></tfoot></table>';
        return $html;
    }

    private static function installment_table( int $agreement_id ): string {
        $rows = Olama_Reg_Agreement_Invoice::get_due_schedule( $agreement_id );
        $html = '<h3>جدول الدفعات</h3><table><thead><tr><th>الدفعة</th><th>المبلغ</th><th>تاريخ الاستحقاق</th></tr></thead><tbody>';
        $total = 0.0;
        foreach ( $rows as $row ) {
            $amount = round( (float) $row->amount_due, 2 );
            $total = round( $total + $amount, 2 );
            $html .= '<tr><td>' . esc_html( $row->display_installment_no ?? $row->installment_no ) . '</td><td>' . number_format( $amount, 2 ) . ' د.أ</td><td>' . esc_html( $row->due_date ) . '</td></tr>';
        }
        return $html . '</tbody><tfoot><tr><th>المجموع النهائي</th><th>' .
            number_format( $total, 2 ) .
            ' د.أ</th><th>يساوي صافي مبلغ العقد</th></tr></tfoot></table>';
    }

    private static function photo_consent(): string {
        return '<p>☐ أوافق على التصوير لأغراض التوثيق الداخلي.</p><p>☐ أوافق على النشر في القنوات الرسمية دون بيانات شخصية حساسة.</p><p>☐ لا أوافق على النشر.</p>';
    }

    private static function has_transport_fee( int $agreement_id ): bool {
        foreach ( Olama_Reg_Agreement_Fees::get_by_agreement( $agreement_id ) as $fee ) {
            $haystack = (string) $fee->label . ' ' . (string) $fee->fee_category;
            if ( preg_match( '/نقل|مواصلات|transport/iu', $haystack ) ) return true;
        }
        return false;
    }

    private static function transportation_summary( array $rows ): string {
        if ( ! $rows ) return __( 'خدمة نقل مدرجة ضمن رسوم العقد.', 'olama-registration' );
        $first = (array) reset( $rows );
        return implode( ' — ', array_filter( [
            $first['route_name'] ?? $first['line_name'] ?? '',
            $first['pickup_point'] ?? $first['location_name'] ?? '',
        ] ) ) ?: __( 'اشتراك نقل مسجل في Olama Core.', 'olama-registration' );
    }

    private static function value( ?object $row, array $keys ): string {
        if ( ! $row ) return '';
        foreach ( $keys as $key ) {
            if ( isset( $row->{$key} ) && trim( (string) $row->{$key} ) !== '' ) {
                return trim( (string) $row->{$key} );
            }
        }
        return '';
    }

    private static function values_joined( array $rows, array $keys ): string {
        $values = [];
        foreach ( $rows as $row ) {
            $val = self::value( is_object( $row ) ? $row : null, $keys );
            if ( $val !== '' ) {
                $values[] = $val;
            }
        }
        return implode( ' ، ', array_unique( $values ) );
    }

    private static function flatten( array $value, string $prefix = '' ): array {
        $flat = [];
        foreach ( $value as $key => $item ) {
            if ( $key === 'components' ) continue;
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if ( is_array( $item ) ) {
                $flat += self::flatten( $item, $path );
            } elseif ( is_scalar( $item ) || $item === null ) {
                $flat[ $path ] = $item;
            }
        }
        return $flat;
    }
}
