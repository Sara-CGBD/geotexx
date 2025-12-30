<?php
session_start();
require_once 'security_config.php';

if (!isset($_SESSION['user_id'])) {
    die("Unauthorized");
}

$reference = "4.0L125OCT28-R03-GT0.9.H0.1";
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test QC API</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Testing QC Summary API</h1>
    <p>Reference: <strong><?php echo htmlspecialchars($reference); ?></strong></p>
    
    <h2>API Response:</h2>
    <div id="result"></div>
    
    <script>
        const reference = '<?php echo addslashes($reference); ?>';
        
        fetch(`api/get_qc_test_summary.php?reference=${encodeURIComponent(reference)}`)
            .then(response => {
                console.log('Response status:', response.status);
                console.log('Response headers:', response.headers);
                return response.text();
            })
            .then(text => {
                console.log('Raw response:', text);
                document.getElementById('result').innerHTML = '<h3>Raw Response:</h3><pre>' + text + '</pre>';
                
                try {
                    const data = JSON.parse(text);
                    console.log('Parsed JSON:', data);
                    document.getElementById('result').innerHTML += '<h3>Parsed JSON:</h3><pre>' + JSON.stringify(data, null, 2) + '</pre>';
                    
                    if (data.success) {
                        document.getElementById('result').innerHTML += '<p class="success">✓ Success: ' + data.tests.length + ' test(s) found</p>';
                    } else {
                        document.getElementById('result').innerHTML += '<p class="error">✗ Error: ' + data.error + '</p>';
                    }
                } catch (e) {
                    document.getElementById('result').innerHTML += '<p class="error">✗ JSON Parse Error: ' + e.message + '</p>';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                document.getElementById('result').innerHTML += '<p class="error">✗ Fetch Error: ' + error.message + '</p>';
            });
    </script>
</body>
</html>


