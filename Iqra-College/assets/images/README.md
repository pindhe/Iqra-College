# Image assets

## Auth (Login & Register)
Background is chosen in this order: **auth-bg.jpg** → **auth-bg.png** → **arday.jpg** → **arday.png** → Unsplash fallback. Use JPG/PNG ~1920×1080 for best result.

## Hero & About (index.php)
- **hero-bg.jpg** – Hero section background. JPG/PNG, e.g. 1920×1080. If missing, `hero-pattern` gradient is used.
- **student.png** – Student/person image on the hero right side. Portrait, rounded. If missing, a placeholder icon is shown.
- **hero-education.svg** – Optional hero graphic (graduation cap, Certified, Video Lessons). Replaced by student.png in current layout.
- **hero-instructor.png** or **hero-instructor.webp** – Instructor photo for About; background-removed PNG, portrait ~400×500.
- **hero-graduation-cap.svg**, **hero-certified-badge.svg**, **hero-video-play.svg** – Standalone icons.

## Removing backgrounds
Use [remove.bg](https://remove.bg), GIMP, or similar. Save as PNG and place in `assets/images/` (e.g. `hero-instructor.png`).

## Placeholders
- **empty-courses.svg** – "No published courses yet".
- **course-placeholder.svg** – Default course card cover when no thumbnail.
