HealthOptima
-----------------------------------------

HealthOptima is a secure, role-based Smart Hospital Management System built using PHP (backend) and HTML, CSS, JavaScript (frontend). It enables streamlined hospital operations such as patient registration, appointment scheduling, user login with roles (admin, doctor, receptionist), and secure session management — all in one lightweight web-based platform.

Here’s everything you need to set up the backend and start integrating your frontend (HTML, CSS, JS) with it.


What’s Included in this ZIP:
-------------------------------
- /healthoptima/         → Main folder for backend + frontend HTML
  ├── functions.php      → Backend logic (PHP)
  ├── db.php             → DB connection
  ├── session.php        → Session handling
  └── /views/            → Frontend HTML files (login, dashboard, etc.)


What You Need To Do:
------------------------

1. Install XAMPP (if not done yet)
   → https://www.apachefriends.org

2. Start Apache and MySQL from XAMPP Control Panel

3. Go to: http://localhost/phpmyadmin
   → Click "Import" and select the SQL file I sent (`healthoptima_db.sql`)
   → This creates all required tables + sample users

4. Place the `healthoptima` folder inside:
   → C:\xampp\htdocs\
   → So the full path looks like: C:\xampp\htdocs\healthoptima\


Test Backend:
----------------
Open in browser:
→ http://localhost/healthoptima/views/login_form.html

Login with one of these sample users:

| Username  | Password   | Role         |
|-----------|------------|--------------|
| admin21   | Admin@21   | admin        |
| doc1      | Doctor@1   | doctor       |
| Recep21   | Recep@21   | receptionist |


 Your Job (Frontend Work):
----------------------------

You can edit the files in `/views/`  
Add styling (CSS) and interactivity (JavaScript)  
Use `fetch()` in JS to call backend APIs like:

```js
fetch("/healthoptima/functions.php?action=register_patient", {
  method: "POST",
  body: new URLSearchParams(formData)
})
.then(res => res.json())
.then(data => {
  // show response message on screen
});

