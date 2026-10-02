/// Course + section + lesson models mirroring the mobile API payloads.
class Course {
  const Course({
    required this.id,
    required this.title,
    this.slug,
    this.description,
    this.shortDescription,
    this.price,
    this.currency,
    this.lessonsCount = 0,
    this.sectionsCount = 0,
    this.isEnrolled = false,
  });

  final String id;
  final String title;
  final String? slug;
  final String? description;
  final String? shortDescription;
  final num? price;
  final String? currency;
  final int lessonsCount;
  final int sectionsCount;
  final bool isEnrolled;

  String get priceLabel {
    if (price == null || price == 0) return 'مجاني';
    return '${price} ${currency ?? 'JOD'}';
  }

  factory Course.fromJson(Map<String, dynamic> json) => Course(
        id: json['id'].toString(),
        title: json['title']?.toString() ?? '',
        slug: json['slug']?.toString(),
        description: json['description']?.toString(),
        shortDescription: json['short_description']?.toString(),
        price: json['price'],
        currency: json['currency']?.toString(),
        lessonsCount: (json['lessons_count'] as num?)?.toInt() ?? 0,
        sectionsCount: (json['sections_count'] as num?)?.toInt() ?? 0,
        isEnrolled: json['is_enrolled'] == true,
      );
}

class EnrolledCourse {
  const EnrolledCourse({required this.course, this.enrolledAt, this.enrollmentId});

  final Course course;
  final DateTime? enrolledAt;
  final String? enrollmentId;

  factory EnrolledCourse.fromJson(Map<String, dynamic> json) => EnrolledCourse(
        enrollmentId: json['enrollment_id']?.toString(),
        enrolledAt: json['enrolled_at'] != null ? DateTime.tryParse(json['enrolled_at'].toString()) : null,
        course: Course.fromJson(json['course'] as Map<String, dynamic>),
      );
}

class Lesson {
  const Lesson({
    required this.id,
    required this.title,
    this.lessonType,
    this.durationMinutes,
    this.isFreePreview = false,
    this.locked = false,
    this.position = 0,
    this.unlockAt,
  });

  final String id;
  final String title;
  final String? lessonType;
  final num? durationMinutes;
  final bool isFreePreview;
  final bool locked;
  final int position;
  final DateTime? unlockAt;

  factory Lesson.fromJson(Map<String, dynamic> json) => Lesson(
        id: json['id'].toString(),
        title: json['title']?.toString() ?? '',
        lessonType: json['lesson_type']?.toString(),
        durationMinutes: json['duration_minutes'],
        isFreePreview: json['is_free_preview'] == true,
        locked: json['locked'] == true,
        position: (json['position'] as num?)?.toInt() ?? 0,
        unlockAt: json['unlock_at'] != null ? DateTime.tryParse(json['unlock_at'].toString()) : null,
      );
}

class Section {
  const Section({required this.id, required this.title, required this.lessons});

  final String id;
  final String title;
  final List<Lesson> lessons;

  factory Section.fromJson(Map<String, dynamic> json) => Section(
        id: json['id'].toString(),
        title: json['title']?.toString() ?? '',
        lessons: (json['lessons'] as List? ?? [])
            .map((e) => Lesson.fromJson(e as Map<String, dynamic>))
            .toList(),
      );
}

class LessonContent {
  const LessonContent({
    required this.id,
    required this.contentType,
    required this.title,
    this.video,
    this.text,
    this.file,
  });

  final String id;
  final String contentType;
  final String title;
  final Map<String, dynamic>? video;
  final Map<String, dynamic>? text;
  final Map<String, dynamic>? file;

  factory LessonContent.fromJson(Map<String, dynamic> json) => LessonContent(
        id: json['id'].toString(),
        contentType: json['content_type']?.toString() ?? '',
        title: json['title']?.toString() ?? '',
        video: json['video'] as Map<String, dynamic>?,
        text: json['text'] as Map<String, dynamic>?,
        file: json['file'] as Map<String, dynamic>?,
      );
}

class Announcement {
  const Announcement({required this.id, required this.title, required this.body, this.publishedAt});

  final String id;
  final String title;
  final String body;
  final DateTime? publishedAt;

  factory Announcement.fromJson(Map<String, dynamic> json) => Announcement(
        id: json['id'].toString(),
        title: json['title']?.toString() ?? '',
        body: json['body']?.toString() ?? '',
        publishedAt: json['published_at'] != null ? DateTime.tryParse(json['published_at'].toString()) : null,
      );
}
