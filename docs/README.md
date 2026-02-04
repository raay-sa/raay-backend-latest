# This folders contains docs of this Custom LMS application

## Major systems and actors

### **Auth & Access:**
+ Register -> Login -> Token issued
+ OTP flow (send -> verify)
+ Token refresh
+ Logout
+ Unauthorized access blocked

### **Teacher (Instructor):**
+ Authenticated client,
+ Create program,
+ Create sections ->  sessions
+ Add assignments / exams
+ Start live stream
+ View student submissions

### **Student:**
+ Authenticated client,
+ Browse programs
+ Add to cart
+ Purchase
+ Enroll
+ Access sessions
+ Submit assignment
+ Take exam
+ Get certificate

### **Admin:**
+ Authenticated client,
+ Have access to everything,
+ Create category,
+ Create program,
+ Assign teacher,
+ View dashboard stats,
+ View students list,
+ Download report (PDF / Excel)