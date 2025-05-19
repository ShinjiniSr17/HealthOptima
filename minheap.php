unction registerPatient() {
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