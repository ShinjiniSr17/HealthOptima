<?php
require_once "db.php";
session_start();

// ROUTER
if (isset($_GET['action'])) {
    switch ($_GET['action']) {
        case 'login': login(); break;
        case 'logout': logout(); break;
        case 'register_user': registerUser(); break;
        case 'register_patient': registerPatient(); break;
        case 'get_appointments': getAppointmentsForDoctor(); break;
        case 'complete_appointment': completeAppointment(); break;
        case 'get_all_users': get_all_users(); break;
        case 'get_all_appointments': get_all_appointments(); break;
        case 'get_all_patients': getAllPatients(); break;

        default:
            echo json_encode(["success" => false, "message" => "Unknown action"]);
    }
}

// ✅ LOGIN FUNCTION
function login() {
    $conn = connectDB();
    
    $username = $_POST['username'];
    $password = $_POST['password'];

    $stmt = $conn->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($user = $res->fetch_assoc()) {
        if (password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['username'] = $user['username'];
            echo json_encode([
                "success" => true,
                "role" => $user['role'],
                "message" => "Login successful"
            ]);
        } else {
            echo json_encode([
                "success" => false,
                "message" => "Wrong password"
            ]);
        }
    } else {
        echo json_encode([
            "success" => false,
            "message" => "User not found"
        ]);
    }
}

// ✅ LOGOUT FUNCTION
function logout() {
    session_destroy();
    echo json_encode(["success" => true, "message" => "Logged out"]);
}

// ✅ USER REGISTRATION
function registerUser() {
    $conn = connectDB();

    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];

    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        echo json_encode([
            "success" => false,
            "message" => "Username already exists"
        ]);
        return;
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
    $stmt->bind_param("sss", $username, $hashed, $role);

    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "User registered successfully"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Failed to register user"
        ]);
    }
}

// ✅ PATIENT REGISTRATION + AUTO DOCTOR ASSIGNMENT (MIN HEAP) + FIFO APPOINTMENT
function registerPatient() {
    $conn = connectDB();

    $name = $_POST['name'];
    $age = $_POST['age'];
    $gender = $_POST['gender'];
    $disease = $_POST['disease'];
    $disease = strtolower(trim($disease));

    $diseaseSpecializationMap = [
    'fever' => 'General Physician',
    'cold' => 'General Physician',
    'cough' => 'General Physician',
    'body ache' => 'General Physician',
    'sore throat' => 'General Physician',
    'headache' => 'General Physician',
    'weakness' => 'General Physician',
    'chills' => 'General Physician',
    'fatigue' => 'General Physician',
    'vomiting' => 'General Physician',
    'diarrhea' => 'General Physician',
    'dizziness' => 'General Physician',
    'stomach pain' => 'Gastroenterology',
    'acidity' => 'Gastroenterology',
    'heart pain' => 'Cardiology',
    'chest pain' => 'Cardiology',
    'asthma' => 'Pulmonology',
    'breathing problem' => 'Pulmonology',
    'skin rash' => 'Dermatology',
    'itching' => 'Dermatology',
    'eye redness' => 'Ophthalmology',
    'blurred vision' => 'Ophthalmology',
    'hearing loss' => 'ENT',
    'ear pain' => 'ENT',
    'throat infection' => 'ENT',
    'paralysis' => 'Neurology',
    'seizure' => 'Neurology',
    'joint pain' => 'Orthopedics',
    'fracture' => 'Orthopedics',
    'growth pain' => 'Pediatrics',
    'child fever' => 'Pediatrics',
    'tumor' => 'Oncology',
    'cancer' => 'Oncology'
];

// Auto-assign specialization if disease matches
if (isset($diseaseSpecializationMap[$disease])) {
    $disease = $diseaseSpecializationMap[$disease];
}
    

    $priority = $_POST['priority'];

    // ✅ Find doctor who matches specialization and is under max patient cap
    $stmt = $conn->prepare("
        SELECT d.id
        FROM doctors d
        LEFT JOIN appointments a ON d.id = a.doctor_id
        WHERE d.specialization = ?
        GROUP BY d.id, d.max_patients
        HAVING COUNT(a.id) < d.max_patients
        ORDER BY COUNT(a.id) ASC
        LIMIT 1
    ");
    $stmt->bind_param("s", $disease);
    $stmt->execute();
    $result = $stmt->get_result();

    $assigned_doctor_id = null;
    if ($row = $result->fetch_assoc()) {
        $assigned_doctor_id = $row['id'];
    } else {
        echo json_encode([
            "success" => false,
            "message" => "No available doctor found for specialization: $disease"
        ]);
        return;
    }

    // ✅ Insert new patient with priority
    $stmt = $conn->prepare("INSERT INTO patients (name, age, gender, disease, priority, assigned_doctor_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sissii", $name, $age, $gender, $disease, $priority, $assigned_doctor_id);

    if ($stmt->execute()) {
        $patient_id = $conn->insert_id;

        // ✅ Create FIFO-based appointment
        $stmt2 = $conn->prepare("INSERT INTO appointments (patient_id, doctor_id) VALUES (?, ?)");
        $stmt2->bind_param("ii", $patient_id, $assigned_doctor_id);
        $stmt2->execute();

        // ✅ Get doctor info
        $docStmt = $conn->prepare("SELECT name, specialization FROM doctors WHERE id = ?");
        $docStmt->bind_param("i", $assigned_doctor_id);
        $docStmt->execute();
        $docResult = $docStmt->get_result();
        $docInfo = $docResult->fetch_assoc();
        $doctorName = $docInfo['name'];
        $specialization = $docInfo['specialization'];

        echo json_encode([
            "success" => true,
            "message" => "Patient registered and appointment booked with {$doctorName} ({$specialization})."
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Error registering patient"
        ]);
    }
}

function getAllPatients() {
    $conn = connectDB();
    $query = "
        SELECT 
            p.name AS patient_name,
            p.age,
            p.gender,
            p.disease,
            d.id AS doctor_id,
            d.name AS doctor_name
        FROM patients p
        LEFT JOIN doctors d ON p.assigned_doctor_id = d.id
        ORDER BY p.id DESC
    ";
    $result = $conn->query($query);

    $patients = [];
    while ($row = $result->fetch_assoc()) {
        $patients[] = $row;
    }

    echo json_encode($patients);
}





// ✅ FETCH APPOINTMENTS (FIFO) FOR A DOCTOR
function getAppointmentsForDoctor() {
    $conn = connectDB();
    $doctor_id = $_GET['doctor_id'];

    $query = "
        SELECT 
            a.id AS appointment_id,
            p.name AS patient_name,
            p.age,
            p.gender,
            p.priority,
            p.disease,
            a.status,
            a.created_at
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        WHERE a.doctor_id = ?
        ORDER BY p.priority ASC, a.created_at ASC
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $doctor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }

    echo json_encode($appointments);
}



// ✅ MARK APPOINTMENT COMPLETE
function completeAppointment() {
    $conn = connectDB();
    $appointment_id = $_POST['appointment_id'];

    $stmt = $conn->prepare("UPDATE appointments SET status = 'complete' WHERE id = ?");
    $stmt->bind_param("i", $appointment_id);

    if ($stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "Appointment marked as complete."
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Failed to update appointment status."
        ]);
    }
}

// ✅ ADMIN: GET ALL USERS
function get_all_users() {
    $conn = connectDB();
    $result = $conn->query("SELECT username, role FROM users ORDER BY role");

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    echo json_encode($users);
}

// ✅ ADMIN: GET ALL APPOINTMENTS
function get_all_appointments() {
    $conn = connectDB();
    $result = $conn->query("
        SELECT 
            a.id, a.status, a.created_at,
            p.name AS patient_name, p.disease,
            d.name AS doctor_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN doctors d ON a.doctor_id = d.id
        ORDER BY a.created_at DESC
    ");

    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }

    echo json_encode($appointments);
}
?>