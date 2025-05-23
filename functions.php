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
        case 'reset_password': resetPassword(); break;
        case 'find_path': findShortestPath(); break;
       case 'prescribe_medicine': prescribeMedicine(); break;
       case 'get_prescriptions': get_prescriptions(); break;

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
    session_unset();
    session_destroy();
    echo json_encode(["success" => true, "message" => "Logged out"]);
}

// ✅ USER REGISTRATION
function registerUser() {
    $conn = connectDB();

    $username = $_POST['username'];
    $password = $_POST['password'];
    $role = $_POST['role'];

    // Check if username already exists
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

    // ✅ Enforce strong password
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@#$%&]).{8,}$/', $password)) {
        echo json_encode([
            "success" => false,
            "message" => "Password must be at least 8 characters, include uppercase, lowercase, number, and special character (@#$%&)."
        ]);
        return;
    }

    // ✅ Hash the password
    $hashed = password_hash($password, PASSWORD_DEFAULT);

    // ✅ Insert new user
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
    $enteredDisease = strtolower(trim($_POST['disease'])); // original for medicine

    // Disease → Specialization mapping
    $diseaseSpecializationMap = [
    // General Physician
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
    'high bp' => 'General Physician',
    'low bp' => 'General Physician',
    'general checkup' => 'General Physician',

    // Gastroenterology
    'stomach pain' => 'Gastroenterology',
    'acidity' => 'Gastroenterology',
    'constipation' => 'Gastroenterology',
    'gas' => 'Gastroenterology',
    'bloating' => 'Gastroenterology',
    'indigestion' => 'Gastroenterology',

    // Cardiology
    'heart pain' => 'Cardiology',
    'chest pain' => 'Cardiology',
    'palpitations' => 'Cardiology',
    'irregular heartbeat' => 'Cardiology',
    'shortness of breath' => 'Cardiology',

    // Pulmonology
    'asthma' => 'Pulmonology',
    'breathing problem' => 'Pulmonology',
    'wheezing' => 'Pulmonology',
    'chronic cough' => 'Pulmonology',
    'snoring' => 'Pulmonology',

    // Dermatology
    'skin rash' => 'Dermatology',
    'itching' => 'Dermatology',
    'acne' => 'Dermatology',
    'eczema' => 'Dermatology',
    'psoriasis' => 'Dermatology',
    'skin allergy' => 'Dermatology',

    // Ophthalmology
    'eye redness' => 'Ophthalmology',
    'blurred vision' => 'Ophthalmology',
    'eye pain' => 'Ophthalmology',
    'watery eyes' => 'Ophthalmology',
    'dry eyes' => 'Ophthalmology',
    'double vision' => 'Ophthalmology',

    // ENT
    'hearing loss' => 'ENT',
    'ear pain' => 'ENT',
    'throat infection' => 'ENT',
    'nasal congestion' => 'ENT',
    'sinus' => 'ENT',
    'voice change' => 'ENT',

    // Neurology
    'paralysis' => 'Neurology',
    'seizure' => 'Neurology',
    'memory loss' => 'Neurology',
    'vertigo' => 'Neurology',
    'numbness' => 'Neurology',
    'migraine' => 'Neurology',

    // Orthopedics
    'joint pain' => 'Orthopedics',
    'fracture' => 'Orthopedics',
    'back pain' => 'Orthopedics',
    'shoulder pain' => 'Orthopedics',
    'knee pain' => 'Orthopedics',
    'sports injury' => 'Orthopedics',

    // Pediatrics
    'growth pain' => 'Pediatrics',
    'child fever' => 'Pediatrics',
    'child diarrhea' => 'Pediatrics',
    'vaccination' => 'Pediatrics',
    'pediatric checkup' => 'Pediatrics',

    // Oncology
    'tumor' => 'Oncology',
    'cancer' => 'Oncology',
    'lump' => 'Oncology',
    'abnormal bleeding' => 'Oncology',

    // Psychiatry
    'anxiety' => 'Psychiatry',
    'depression' => 'Psychiatry',
    'insomnia' => 'Psychiatry',
    'mood swings' => 'Psychiatry',
    'panic attacks' => 'Psychiatry'
];


    // Determine specialization from entered disease
   if (isset($diseaseSpecializationMap[$enteredDisease])) {
    $specialization = $diseaseSpecializationMap[$enteredDisease];
} else {
    // Try using the disease directly as a specialization (e.g., "Orthopedics")
    $stmt = $conn->prepare("SELECT DISTINCT specialization FROM doctors WHERE specialization = ?");
    $stmt->bind_param("s", $enteredDisease);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($row = $res->fetch_assoc()) {
        $specialization = $row['specialization'];  // Disease is a valid specialization
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Disease not recognized for specialization mapping, and no matching doctor found."
        ]);
        return;
    }
}


    $priority = $_POST['priority'];

    // ✅ Find doctor matching specialization with capacity
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
    $stmt->bind_param("s", $specialization);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$row = $result->fetch_assoc()) {
        echo json_encode([
            "success" => false,
            "message" => "No available doctor found for specialization: $specialization"
        ]);
        return;
    }

    $assigned_doctor_id = $row['id'];

    // ✅ Save patient (with original disease string!)
    $stmt = $conn->prepare("
        INSERT INTO patients (name, age, gender, disease, priority, assigned_doctor_id)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("sissii", $name, $age, $gender, $enteredDisease, $priority, $assigned_doctor_id);

    if ($stmt->execute()) {
        $patient_id = $conn->insert_id;

        // ✅ Create appointment (FIFO)
        $stmt2 = $conn->prepare("INSERT INTO appointments (patient_id, doctor_id) VALUES (?, ?)");
        $stmt2->bind_param("ii", $patient_id, $assigned_doctor_id);
        $stmt2->execute();

        // ✅ Get doctor info
        $docStmt = $conn->prepare("SELECT name, specialization FROM doctors WHERE id = ?");
        $docStmt->bind_param("i", $assigned_doctor_id);
        $docStmt->execute();
        $docResult = $docStmt->get_result();
        $docInfo = $docResult->fetch_assoc();

        echo json_encode([
            "success" => true,
            "message" => "Patient registered and appointment booked with {$docInfo['name']} ({$docInfo['specialization']})."
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
    $query = "
        SELECT 
            a.id, a.status, a.created_at,
            p.name AS patient_name, p.disease,
            d.name AS doctor_name
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        JOIN doctors d ON a.doctor_id = d.id
        ORDER BY a.created_at DESC
    ";

    $result = $conn->query($query);
    $appointments = [];
    while ($row = $result->fetch_assoc()) {
        $appointments[] = $row;
    }
    echo json_encode($appointments);
}
//pass reset
function resetPassword() {
    $conn = connectDB();

    $username = $_POST['username'];
    $newPassword = $_POST['password'];

    // Strong password check
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@#$%&]).{8,}$/', $newPassword)) {
        echo json_encode([
            "success" => false,
            "message" => "Password must be strong (uppercase, number, special character, 8+ chars)."
        ]);
        return;
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows == 0) {
        echo json_encode(["success" => false, "message" => "User not found"]);
        return;
    }

    $hashed = password_hash($newPassword, PASSWORD_DEFAULT);
    $updateStmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE username = ?");
    $updateStmt->bind_param("ss", $hashed, $username);
    if ($updateStmt->execute()) {
        echo json_encode(["success" => true, "message" => "Password has been reset successfully."]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to reset password."]);
    }
}
function getHospitalGraph() {
    return [
        "Entrance" => ["Reception" => 2, "Pharmacy" => 4, "Waiting Area" => 3],
        "Reception" => ["OPD" => 3, "ENT" => 6, "Waiting Area" => 1],
        "Waiting Area" => ["Radiology" => 4, "Pharmacy" => 3],
        "Pharmacy" => ["Lab" => 2],
        "Lab" => ["Radiology" => 3, "ICU" => 6],
        "OPD" => ["Cardiology" => 5, "Oncology" => 10],
        "Cardiology" => ["ICU" => 4],
        "ENT" => ["Dermatology" => 4, "Ophthalmology" => 5],
        "Dermatology" => ["Psychiatry" => 6],
        "Ophthalmology" => ["Neurology" => 7],
        "Neurology" => ["ICU" => 5],
        "Psychiatry" => ["Pediatrics" => 4],
        "Pediatrics" => ["Oncology" => 7],
        "Oncology" => ["ICU" => 5],

        // 🔁 Add terminal nodes explicitly as keys with empty arrays:
        "Radiology" => [],
        "ICU" => [],
    ];
}

function dijkstra($graph, $start, $end) {
    $dist = [];
    $prev = [];
    $queue = [];

    foreach ($graph as $node => $_) {
        $dist[$node] = INF;
        $prev[$node] = null;
        $queue[$node] = true;
    }

    $dist[$start] = 0;

    while (!empty($queue)) {
        $minNode = null;
        foreach ($queue as $node => $_) {
            if ($minNode === null || $dist[$node] < $dist[$minNode]) {
                $minNode = $node;
            }
        }

        if ($minNode === $end) break;

        foreach ($graph[$minNode] as $neighbor => $weight) {
            if (!isset($queue[$neighbor])) continue;

            $alt = $dist[$minNode] + $weight;
            if ($alt < $dist[$neighbor]) {
                $dist[$neighbor] = $alt;
                $prev[$neighbor] = $minNode;
            }
        }

        unset($queue[$minNode]);
    }

    // Reconstruct path
    $path = [];
    for ($at = $end; $at !== null; $at = $prev[$at]) {
        array_unshift($path, $at);
    }

    return $path[0] === $start ? $path : [];
}
function findShortestPath() {
    $graph = getHospitalGraph();
    $start = $_GET['start'] ?? 'Entrance';
    $end = $_GET['end'] ?? '';

    if (!isset($graph[$start]) || !isset($graph[$end])) {
        echo json_encode(["success" => false, "message" => "Invalid start or end location."]);
        return;
    }

    $path = dijkstra($graph, $start, $end);
    echo json_encode(["success" => true, "path" => $path]);
}
function prescribeMedicine() {
    $conn = connectDB();
    $appointment_id = $_POST['appointment_id'];

    // ✅ Get original disease and priority from patient
    $query = "
        SELECT p.disease, p.priority
        FROM appointments a
        JOIN patients p ON a.patient_id = p.id
        WHERE a.id = ?
    ";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();

    if (!$row = $res->fetch_assoc()) {
        echo json_encode(["success" => false, "message" => "Patient not found for appointment."]);
        return;
    }

    $disease = strtolower(trim($row['disease']));  // this is the original disease like "fever"
    $priority = (int)$row['priority'];
    $level = ($priority >= 4) ? 'low' : 'high';

    // ✅ Find medicine for the actual disease (not specialization)
    $medQuery = $conn->prepare("SELECT medicine_name FROM medicines WHERE LOWER(disease) = ? AND priority_level = ?");
    $medQuery->bind_param("ss", $disease, $level);
    $medQuery->execute();
    $medRes = $medQuery->get_result();

    if ($medRow = $medRes->fetch_assoc()) {
        $medicine = $medRow['medicine_name'];
    } else {
        echo json_encode(["success" => false, "message" => "No medicine available in the informatory."]);
        return;
    }

    // ✅ Store medicine in prescriptions table
    $insert = $conn->prepare("INSERT INTO prescriptions (appointment_id, medicine) VALUES (?, ?)");
    $insert->bind_param("is", $appointment_id, $medicine);

    if ($insert->execute()) {
        echo json_encode(["success" => true, "message" => "Medicine prescribed: $medicine"]);
    } else {
        echo json_encode(["success" => false, "message" => "Failed to prescribe."]);
    }
}

function get_prescriptions() {
    $conn = connectDB();
    $query = "
        SELECT 
            pr.medicine, 
            pr.created_at,
            p.name AS patient_name,
            p.disease,
            p.priority
        FROM prescriptions pr
        JOIN appointments a ON pr.appointment_id = a.id
        JOIN patients p ON a.patient_id = p.id
        ORDER BY pr.created_at DESC
    ";
    $result = $conn->query($query);

    $prescriptions = [];
    while ($row = $result->fetch_assoc()) {
        $prescriptions[] = $row;
    }

    echo json_encode($prescriptions);
}




?>
