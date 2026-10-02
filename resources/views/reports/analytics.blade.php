<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 18mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1e293b; line-height: 1.6; }
        h1 { font-size: 20px; margin: 0 0 2px; color: #0f172a; }
        h2 { font-size: 14px; margin: 18px 0 8px; padding-bottom: 4px; border-bottom: 2px solid #2563eb; color: #0f172a; }
        .sub { color: #64748b; font-size: 10px; margin-bottom: 16px; }
        .grid { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 6px; }
        .card { flex: 1 1 22%; min-width: 120px; border: 1px solid #e2e8f0; border-radius: 8px; padding: 8px 10px; }
        .card .label { font-size: 9px; color: #64748b; }
        .card .value { font-size: 15px; font-weight: bold; color: #0f172a; }
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { background: #eff6ff; color: #1e40af; font-size: 9.5px; text-align: right; padding: 5px 7px; border: 1px solid #dbeafe; }
        td { font-size: 10px; padding: 4px 7px; border: 1px solid #e2e8f0; }
        tr:nth-child(even) td { background: #f8fafc; }
        .muted { color: #94a3b8; }
        .footer { margin-top: 24px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
    <h1>التقرير التحليلي</h1>
    <div class="sub">Analytics Report · {{ $generated_at->format('Y-m-d H:i') }}</div>

    @php $ov = $data['overview']; @endphp

    <div class="grid">
        <div class="card"><div class="label">عدد الطلاب / Students</div><div class="value">{{ $ov['total_students'] }}</div></div>
        <div class="card"><div class="label">الكورسات / Courses</div><div class="value">{{ $ov['total_courses'] }}</div></div>
        <div class="card"><div class="label">التسجيلات / Enrollments</div><div class="value">{{ $ov['total_enrollments'] }}</div></div>
        <div class="card"><div class="label">نسبة النجاح / Pass rate</div><div class="value">{{ $ov['pass_rate'] }}%</div></div>
        <div class="card"><div class="label">نسبة الإكمال / Completion</div><div class="value">{{ $ov['completion_rate'] }}%</div></div>
        <div class="card"><div class="label">الإيرادات / Revenue</div><div class="value">{{ number_format($ov['revenue'], 2) }}</div></div>
        <div class="card"><div class="label">الأرباح / Profit</div><div class="value">{{ number_format($ov['profit'], 2) }}</div></div>
        <div class="card"><div class="label">مدة المشاهدة / Watch</div><div class="value">{{ number_format($ov['watch_hours'], 1) }} س</div></div>
    </div>

    <h2>أكثر الكورسات مبيعاً — Best Selling Courses</h2>
    <table>
        <tr><th>#</th><th>الكورس</th><th>المبيعات</th><th>الإيرادات</th><th>الطلاب المكتملون</th><th>مدة المشاهدة (ثانية)</th></tr>
        @foreach (array_slice($data['best_selling'], 0, 10) as $i => $c)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $c['title'] }}</td>
                <td>{{ $c['sales'] }}</td>
                <td>{{ number_format($c['revenue'], 2) }}</td>
                @php $full = collect($data['courses'])->firstWhere('id', $c['course_id']); @endphp
                <td>{{ $full['completed'] ?? 0 }}</td>
                <td>{{ number_format($full['watch_seconds'] ?? 0) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>مصادر الزيارات — Traffic Sources</h2>
    <table>
        <tr><th>المصدر</th><th>التسجيلات</th><th>النسبة</th><th>الإيرادات</th></tr>
        @foreach ($data['traffic_sources'] as $s)
            <tr>
                <td>{{ $s['source'] }}</td>
                <td>{{ $s['total'] }}</td>
                <td>{{ $s['percentage'] }}%</td>
                <td>{{ number_format($s['revenue'], 2) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>أداء الكورسات — Course Performance</h2>
    <table>
        <tr><th>الكورس</th><th>التسجيلات</th><th>مكتمل</th><th>نسبة الإكمال</th><th>متوسط التقدم</th><th>محاولات الامتحان</th><th>الإيرادات</th></tr>
        @foreach ($data['courses'] as $c)
            <tr>
                <td>{{ $c['title'] }}</td>
                <td>{{ $c['enrollments'] }}</td>
                <td>{{ $c['completed'] }}</td>
                <td>{{ $c['completion_rate'] }}%</td>
                <td>{{ $c['avg_progress'] }}%</td>
                <td>{{ $c['attempts'] }}</td>
                <td>{{ number_format($c['revenue'], 2) }}</td>
            </tr>
        @endforeach
    </table>

    <h2>أداء الاختبارات — Exam Performance</h2>
    <table>
        <tr><th>الاختبار</th><th>المحاولات</th><th>متوسط الدرجة</th><th>نسبة النجاح</th><th>الأعلى</th><th>الأدنى</th></tr>
        @foreach ($data['exams'] as $e)
            <tr>
                <td>{{ $e['title'] }}</td>
                <td>{{ $e['attempts'] }}</td>
                <td>{{ $e['avg_score'] }}%</td>
                <td>{{ $e['pass_rate'] }}%</td>
                <td>{{ $e['highest'] ?? '—' }}%</td>
                <td>{{ $e['lowest'] ?? '—' }}%</td>
            </tr>
        @endforeach
    </table>

    <div class="footer">
        <span>{{ config('app.name', 'Tadreesly') }}</span>
        <span>تم الإنشاء / Generated: {{ $generated_at->format('Y-m-d H:i') }}</span>
    </div>
</body>
</html>
