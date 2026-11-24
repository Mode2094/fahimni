<!DOCTYPE html>
<html dir="rtl" lang="ar" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <title>{{ $platformNameFull }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f1f5f9; font-family:'Segoe UI', Tahoma, Arial, sans-serif; direction:rtl; text-align:right;">
    <div style="display:none; max-height:0; overflow:hidden; opacity:0;">
        انضم {{ $teacherName }} إلى {{ $platformNameFull }} — إجمالي المعلمين الآن {{ $stats['total'] }}.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f1f5f9; padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%; max-width:600px; background-color:#ffffff; border-radius:16px; overflow:hidden; box-shadow:0 1px 3px rgba(15,23,42,0.08);">

                    {{-- ─── Header / brand ─────────────────────────────── --}}
                    <tr>
                        <td style="background-color:#0d9488; background-image:linear-gradient(135deg,#0d9488 0%,#0f766e 100%); padding:28px 32px; text-align:right;">
                            @if($logoUrl)
                                <img src="{{ $logoUrl }}" alt="{{ $platformNameFull }}" width="132" style="display:block; border:0; outline:none; text-decoration:none; margin-bottom:12px;">
                            @endif
                            <p style="margin:0; color:#ffffff; font-size:20px; font-weight:700; letter-spacing:0.2px;">{{ $platformNameFull }}</p>
                            <p style="margin:6px 0 0; color:#ccfbf1; font-size:13px;">إشعار إداري — تسجيل معلّم جديد</p>
                        </td>
                    </tr>

                    {{-- ─── Headline ───────────────────────────────────── --}}
                    <tr>
                        <td style="padding:28px 32px 8px;">
                            <p style="margin:0 0 10px; color:#0f172a; font-size:22px; font-weight:800; line-height:1.5;">🎉 مبروك!</p>
                            <p style="margin:0; color:#334155; font-size:15px; line-height:1.8;">
                                انضم معلّم جديد إلى عائلة {{ $platformNameFull }}.
                            </p>
                        </td>
                    </tr>

                    {{-- ─── Teacher details ────────────────────────────── --}}
                    <tr>
                        <td style="padding:16px 32px 4px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 20px;">
                                        <p style="margin:0 0 12px; color:#0f766e; font-size:13px; font-weight:700;">بيانات المعلّم</p>
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:6px 0; color:#64748b; font-size:13px; width:150px;">اسم المعلم</td>
                                                <td style="padding:6px 0; color:#0f172a; font-size:14px; font-weight:600;">{{ $teacherName }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding:6px 0; color:#64748b; font-size:13px;">البريد الإلكتروني</td>
                                                <td style="padding:6px 0; color:#0f172a; font-size:14px; direction:ltr; text-align:right;">{{ $teacherEmail }}</td>
                                            </tr>
                                            @if($teacherPlatformName)
                                            <tr>
                                                <td style="padding:6px 0; color:#64748b; font-size:13px;">اسم المنصة التعليمية</td>
                                                <td style="padding:6px 0; color:#0f172a; font-size:14px; font-weight:600;">{{ $teacherPlatformName }}</td>
                                            </tr>
                                            @endif
                                            <tr>
                                                <td style="padding:6px 0; color:#64748b; font-size:13px;">نوع الاشتراك</td>
                                                <td style="padding:6px 0;">
                                                    <span style="display:inline-block; background-color:#ccfbf1; color:#0f766e; font-size:12px; font-weight:700; padding:3px 10px; border-radius:999px;">{{ $subscriptionType }}</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:6px 0; color:#64748b; font-size:13px;">تاريخ التسجيل</td>
                                                <td style="padding:6px 0; color:#0f172a; font-size:14px;">{{ $registeredAt }}</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ─── Stats ──────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:20px 32px 4px;">
                            <p style="margin:0 0 12px; color:#0f172a; font-size:16px; font-weight:700;">إحصائيات {{ $platformNameFull }} الحالية</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="50%" style="padding:0 0 10px 6px; vertical-align:top;">
                                        <div style="background-color:#f0fdfa; border:1px solid #99f6e4; border-radius:12px; padding:14px 16px;">
                                            <p style="margin:0; color:#0f766e; font-size:26px; font-weight:800; line-height:1.2;">{{ $stats['total'] }}</p>
                                            <p style="margin:4px 0 0; color:#475569; font-size:12px;">إجمالي المعلمين المسجلين</p>
                                        </div>
                                    </td>
                                    <td width="50%" style="padding:0 6px 10px 0; vertical-align:top;">
                                        <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px;">
                                            <p style="margin:0; color:#0f172a; font-size:26px; font-weight:800; line-height:1.2;">{{ $stats['today'] }}</p>
                                            <p style="margin:4px 0 0; color:#475569; font-size:12px;">المعلمون الجدد اليوم</p>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td width="50%" style="padding:0 0 0 6px; vertical-align:top;">
                                        <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px;">
                                            <p style="margin:0; color:#0f172a; font-size:26px; font-weight:800; line-height:1.2;">{{ $stats['this_month'] }}</p>
                                            <p style="margin:4px 0 0; color:#475569; font-size:12px;">المعلمون الجدد هذا الشهر</p>
                                        </div>
                                    </td>
                                    <td width="50%" style="padding:0 6px 0 0; vertical-align:top;">
                                        <div style="background-color:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px 16px;">
                                            <p style="margin:0; color:#0f172a; font-size:26px; font-weight:800; line-height:1.2;">{{ $stats['paid_subscribers'] }}</p>
                                            <p style="margin:4px 0 0; color:#475569; font-size:12px;">المشتركون المدفوعون</p>
                                        </div>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- ─── CTA ────────────────────────────────────────── --}}
                    @if($actionUrl)
                    <tr>
                        <td style="padding:20px 32px 4px; text-align:center;">
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                <tr>
                                    <td style="background-color:#0d9488; border-radius:10px;">
                                        <a href="{{ $actionUrl }}" target="_blank" style="display:inline-block; padding:12px 26px; color:#ffffff; font-size:14px; font-weight:700; text-decoration:none;">عرض قائمة المعلمين في لوحة الإدارة</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    @endif

                    {{-- ─── Footer ─────────────────────────────────────── --}}
                    <tr>
                        <td style="padding:24px 32px 28px;">
                            <hr style="border:0; border-top:1px solid #e2e8f0; margin:0 0 16px;">
                            <p style="margin:0; color:#94a3b8; font-size:12px; line-height:1.8;">
                                هذا إشعار تلقائي من {{ $platformNameFull }} عند نجاح تسجيل معلّم جديد.
                                الأرقام محسوبة لحظيًا من قاعدة بيانات المنصة بتوقيت الأردن.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
