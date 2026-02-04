# Custom LMS – Backend Documentation

This directory contains internal documentation for the **Custom Learning Management System (LMS)** backend application.

The purpose of these documents is to:
- Explain **how the system works**
- Describe **who can do what** (actors)
- Document **core business flows**
- Help developers, testers, and maintainers understand the system quickly

---

## System Overview

This LMS is a **monolithic Laravel backend** that exposes APIs consumed by one or more frontend clients (web / mobile).

The system is organized around **actors** (roles) and **business flows**, rather than individual endpoints.

---

## Major Systems & Actors

### Authentication & Access Control

Responsible for identity, access, and security across the system.

**Key capabilities:**
- User registration and login
- OTP-based verification flow (send → verify)
- Token issuance and refresh
- Logout and token invalidation
- Role-based access control
- Blocking unauthorized access to protected resources

**Actors involved:**
- Public (Guest)
- Student
- Teacher
- Admin

---

### Teacher (Instructor)

Teachers are responsible for **creating and managing learning content**.

**Capabilities:**
- Authenticate and access instructor-only APIs
- Create and manage programs
- Create sections and sessions within programs
- Add assignments and exams
- Start and manage live streams
- View and evaluate student submissions

**Primary focus:**
Content creation, delivery, and student evaluation.

---

### Student

Students are the **primary consumers** of the LMS.

**Capabilities:**
- Authenticate and access student-only APIs
- Browse available programs
- Add programs to cart
- Purchase programs
- Enroll in programs
- Access sessions and learning materials
- Submit assignments
- Take exams
- Receive and download certificates

**Primary focus:**
Learning, participation, and completion.

---

### Admin

Admins manage and oversee the **entire LMS ecosystem**.

**Capabilities:**
- Authenticate with full system privileges
- Manage users (students, teachers, other admins)
- Create and manage categories
- Create, approve, and manage programs
- Assign teachers to programs
- View dashboards and system statistics
- Monitor orders, transactions, and enrollments
- Download reports (PDF / Excel)
- Configure system-level settings

**Primary focus:**
System control, moderation, and reporting.

---

## Documentation Structure

The documentation is organized into the following sections:

docs/
├── README.md              # High-level system overview (this file)
├── actors/                # Role-based responsibilities and permissions
│   ├── public.md
│   ├── student.md
│   ├── teacher.md
│   ├── admin.md
│   └── system.md
├── flows/                 # End-to-end business flows
    ├── authentication.md
    ├── program_lifecycle.md
    ├── enrollment.md
    ├── payment.md
    ├── live_class.md
    ├── assignment_exam.md
    └── certificate.md
