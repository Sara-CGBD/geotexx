<!DOCTYPE html>
<html>
<head>
    <title>Test QC Form Submit</title>
    <style>
        body { font-family: Arial; padding: 20px; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>QC Form Submit Test</h1>
    
    <h2>POST Data:</h2>
    <pre><?php print_r($_POST); ?></pre>
    
    <h2>Browser Console Instructions:</h2>
    <ol>
        <li>Press F12 to open Developer Tools</li>
        <li>Click on the "Console" tab</li>
        <li>Check for any JavaScript errors (shown in red)</li>
        <li>Try clicking Submit button again</li>
        <li>Check if any errors appear in the console</li>
    </ol>
    
    <h2>Common Issues:</h2>
    <ul>
        <li><strong>If button is not responding:</strong> Check browser console for JavaScript errors</li>
        <li><strong>If form submits but shows error:</strong> Check the error message at the top of the QC Test Order page</li>
        <li><strong>If button is grayed out:</strong> Form might be disabled by JavaScript</li>
    </ul>
    
    <a href="qc_test_order.php">Back to QC Test Order</a>
</body>
</html>


