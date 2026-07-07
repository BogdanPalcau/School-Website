Rangoon International Education Online — LMS

A lightweight Learning Management System built with PHP and SQLite for Rangoon International Education Online.

It's a single, central place for students, teachers, supervisors, and admins to handle everything from course materials and submissions to schedules, discussions, and academic integrity reviews, without needing a dozen different tools bolted together.

This project started as a full-stack web app built with a real focus on education, usability, role-based access control, and keeping course data handled securely.

---

WHAT IT CAN DO

Course management
- Create and manage courses
- Organise materials into course folders
- Upload learning resources: PDFs, Word docs, PowerPoint files, text files, spreadsheets
- Add external learning links
- Lock folders or items when you need to restrict access
- Manage course schedules, rooms, notices, and deadlines

Student portal
- View enrolled courses
- Access course materials
- Open supported documents straight in the browser, no downloading required
- Submit assignment files
- Keep track of deadlines and submission status
- Join course discussions
- Catch up on announcements and course updates

Teacher and supervisor tools
- Manage assigned courses
- Create assignments and submission slots
- Review student submissions
- Add feedback and grades
- View originality and integrity information
- Annotate and comment directly on submitted work
- Manage course discussions and announcements

Admin and owner tools
- Manage users and roles
- Assign teachers and supervisors to courses
- Enrol students onto courses
- Configure site-wide settings
- Manage announcements
- Review system-level data and access control

Academic integrity and review
The system includes an academic review workflow built to support teacher judgement, not replace it. It can generate submission receipts, extract document text, calculate similarity-style indicators, surface writing/process signals, and optionally plug into external AI-detection services when configured.

Security
- Role-based access control
- Course-level access checks
- CSRF protection on sensitive actions
- Login throttling
- Safer upload validation
- Protected database and upload folders
- Rich-text sanitisation for discussions and announcements
- Blocks unsafe HTML, JavaScript links, and dangerous embedded content
- Playwright-based security tests

---

TECH STACK

Backend: PHP 8+
Database: SQLite via PDO
Frontend: HTML, CSS, JavaScript
Local dev: XAMPP / Apache
Testing: Playwright
Document previews (optional): LibreOffice

---

PROJECT STRUCTURE

School-Website/
├── public/                 Public web pages
│   ├── index.php
│   ├── login.php
│   ├── courses.php
│   ├── course.php
│   ├── admin.php
│   ├── download.php
│   ├── preview.php
│   └── view.php
│
├── database/                Local SQLite database storage
│   └── portal.db            Generated locally, don't commit this
│
├── uploads/                  Uploaded course files and submissions
│
├── tests/
│   └── security/             Playwright security tests
│
├── bootstrap.php             Core helpers, auth, database, security utilities
├── db_init.php               Database setup and seed data
├── course_catalog.php        Course data/helpers
├── integrity.php             Academic integrity and review helpers
├── package.json               Test scripts and Playwright dependency
└── README.md
