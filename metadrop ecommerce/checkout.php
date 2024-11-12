<!-- process_payment.php -->
<?php
// M-Pesa API Integration
require_once 'vendor/autoload.php';

// M-Pesa API credentials
$consumer_key = "YOUR_CONSUMER_KEY";
$consumer_secret = "YOUR_CONSUMER_SECRET";
$business_shortcode = "YOUR_SHORTCODE";
$passkey = "YOUR_PASSKEY";
$callback_url = "YOUR_CALLBACK_URL";

// Function to generate access token
function getAccessToken($consumer_key, $consumer_secret) {
    $url = 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Basic ' . base64_encode($consumer_key . ':' . $consumer_secret)]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    
    $result = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $result = json_decode($result);
    
    return $result->access_token;
}

// Process the payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = $_POST['phone'];
    $amount = $_POST['amount'];
    
    // Get access token
    $access_token = getAccessToken($consumer_key, $consumer_secret);
    
    // Prepare STK Push
    $timestamp = date('YmdHis');
    $password = base64_encode($business_shortcode . $passkey . $timestamp);
    
    $curl_post_data = array(
        'BusinessShortCode' => $business_shortcode,
        'Password' => $password,
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => $amount,
        'PartyA' => $phone,
        'PartyB' => $business_shortcode,
        'PhoneNumber' => $phone,
        'CallBackURL' => $callback_url,
        'AccountReference' => 'Metadrop Store',
        'TransactionDesc' => 'Payment for order'
    );
    
    $url = 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest';
    
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, $url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array(
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ));
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
        // ... (previous code remains the same)
        
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($curl_post_data));
        
        $response = curl_exec($curl);
        
        if ($response === false) {
            echo json_encode(['success' => false, 'message' => curl_error($curl)]);
        } else {
            $result = json_decode($response);
            
            if (isset($result->ResponseCode) && $result->ResponseCode == "0") {
                // Store order details in database
                $order_id = saveOrder($_POST);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'STK Push sent successfully',
                    'order_id' => $order_id,
                    'checkout_request_id' => $result->CheckoutRequestID
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'message' => 'STK Push failed: ' . $result->errorMessage
                ]);
            }
        }
        
        curl_close($curl);
    }
    
    // Function to save order details
    function saveOrder($post_data) {
        $db = new PDO("mysql:host=localhost;dbname=metadrop", "username", "password");
        
        $sql = "INSERT INTO orders (
            full_name, 
            email, 
            phone, 
            location, 
            requires_delivery, 
            total_amount, 
            status,
            created_at
        ) VALUES (
            :full_name,
            :email,
            :phone,
            :location,
            :requires_delivery,
            :total_amount,
            'pending',
            NOW()
        )";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':full_name' => $post_data['fullName'],
            ':email' => $post_data['email'],
            ':phone' => $post_data['phone'],
            ':location' => $post_data['location'],
            ':requires_delivery' => isset($post_data['delivery']) ? 1 : 0,
            ':total_amount' => $post_data['amount']
        ]);
        
        return $db->lastInsertId();
    }
    ?>
    
    <!-- mpesa_callback.php -->
    <?php
    // Callback URL to handle M-Pesa payment confirmation
    header("Content-Type: application/json");
    
    // Get the callback data
    $callbackData = file_get_contents('php://input');
    $data = json_decode($callbackData);
    
    // Connect to database
    $db = new PDO("mysql:host=localhost;dbname=metadrop", "username", "password");
    
    // Log callback for debugging
    $sql = "INSERT INTO mpesa_callbacks (data, created_at) VALUES (:data, NOW())";
    $stmt = $db->prepare($sql);
    $stmt->execute([':data' => $callbackData]);
    
    if(isset($data->Body->stkCallback)) {
        $result = $data->Body->stkCallback;
        $checkout_request_id = $result->CheckoutRequestID;
        
        // Find the order by checkout request ID
        $sql = "SELECT id FROM orders WHERE checkout_request_id = :checkout_request_id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':checkout_request_id' => $checkout_request_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($order) {
            if($result->ResultCode == 0) {
                // Payment successful
                $sql = "UPDATE orders SET 
                        status = 'paid',
                        payment_date = NOW(),
                        transaction_code = :transaction_code
                        WHERE id = :order_id";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':transaction_code' => $result->CallbackMetadata->Item[1]->Value,
                    ':order_id' => $order['id']
                ]);
                
                // Send confirmation email to customer
                sendOrderConfirmation($order['id']);
            } else {
                // Payment failed
                $sql = "UPDATE orders SET 
                        status = 'failed',
                        failure_reason = :reason
                        WHERE id = :order_id";
                
                $stmt = $db->prepare($sql);
                $stmt->execute([
                    ':reason' => $result->ResultDesc,
                    ':order_id' => $order['id']
                ]);
            }
        }
    }
    
    // Send response back to M-Pesa
    echo json_encode([
        "ResultCode" => 0,
        "ResultDesc" => "Confirmation received successfully"
    ]);
    
    // Function to send order confirmation
    function sendOrderConfirmation($order_id) {
        $db = new PDO("mysql:host=localhost;dbname=metadrop", "username", "password");
        
        // Get order details
        $sql = "SELECT * FROM orders WHERE id = :order_id";
        $stmt = $db->prepare($sql);
        $stmt->execute([':order_id' => $order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Email content
        $to = $order['email'];
        $subject = "Order Confirmation - Metadrop Store";
        $message = "
        <html>
        <head>
            <title>Order Confirmation</title>
        </head>
        <body>
            <h2>Thank you for your order!</h2>
            <p>Dear {$order['full_name']},</p>
            <p>Your order has been confirmed and payment received.</p>
            <p><strong>Order Details:</strong></p>
            <ul>
                <li>Order ID: {$order['id']}</li>
                <li>Amount Paid: KES {$order['total_amount']}</li>
                <li>Transaction Code: {$order['transaction_code']}</li>
                <li>Delivery Address: {$order['location']}</li>
            </ul>
            <p>We will process your order shortly.</p>
            <p>Best regards,<br>Metadrop Store Team</p>
        </body>
        </html>
        ";
        
        // Headers for HTML email
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: Metadrop Store <noreply@metadrop.com>\r\n";
        
        // Send email
        mail($to, $subject, $message, $headers);
    }
    ?>
    
    <!-- database.sql -->
    CREATE TABLE orders (
        id INT PRIMARY KEY AUTO_INCREMENT,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        location TEXT NOT NULL,
        requires_delivery TINYINT(1) DEFAULT 0,
        total_amount DECIMAL(10,2) NOT NULL,
        status VARCHAR(20) NOT NULL,
        checkout_request_id VARCHAR(100),
        transaction_code VARCHAR(50),
        payment_date DATETIME,
        failure_reason TEXT,
        created_at DATETIME NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    );
    
    CREATE TABLE mpesa_callbacks (
        id INT PRIMARY KEY AUTO_INCREMENT,
        data TEXT NOT NULL,
        created_at DATETIME NOT NULL
    );
    
    CREATE TABLE order_items (
        id INT PRIMARY KEY AUTO_INCREMENT,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        quantity INT NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        created_at DATETIME NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id)
    );