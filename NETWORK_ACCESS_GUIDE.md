# 🌐 Network Access Guide - GEOCIL Automation System

## **How Users Can Access the System from Their Laptops**

---

## **📋 PREREQUISITES**

- ✅ XAMPP is installed and running on the server computer
- ✅ Apache and MySQL services are running
- ✅ The application is accessible at `http://localhost/geotexx` on the server
- ✅ Server computer and user laptops are on the same network (same Wi-Fi/LAN)

---

## **🔧 STEP 1: Find Server IP Address**

### **On the Server Computer (Windows):**

1. **Method 1: Using Command Prompt**

   ```cmd
   ipconfig
   ```

   - Look for **IPv4 Address** under your active network adapter
   - Example: `192.168.1.100` or `10.0.0.50`

2. **Method 2: Using PowerShell**

   ```powershell
   Get-NetIPAddress -AddressFamily IPv4 | Where-Object {$_.InterfaceAlias -notlike "*Loopback*"}
   ```

3. **Method 3: Using Network Settings**
   - Open **Settings** → **Network & Internet** → **Ethernet** (or **Wi-Fi**)
   - Click on your connection
   - Find **IPv4 address**

**Note:** Write down this IP address. You'll need to share it with users.

---

## **🔓 STEP 2: Configure XAMPP to Accept Remote Connections**

### **2.1 Edit Apache Configuration**

1. Open **XAMPP Control Panel**
2. Click **Config** button next to Apache
3. Select **httpd.conf**
4. Find this line (around line 280):
   ```apache
   Listen 80
   ```
5. Change it to:

   ```apache
   Listen 0.0.0.0:80
   ```

   This allows Apache to accept connections from any IP address.

6. Find this section (around line 245):
   ```apache
   <Directory />
       AllowOverride none
       Require all denied
   </Directory>
   ```
7. Change it to:

   ```apache
   <Directory />
       AllowOverride none
       Require all granted
   </Directory>
   ```

8. Find this section (around line 270):
   ```apache
   <Directory "C:/xampp/htdocs">
       Options Indexes FollowSymLinks
       AllowOverride None
       Require local
   </Directory>
   ```
9. Change `Require local` to:

   ```apache
   <Directory "C:/xampp/htdocs">
       Options Indexes FollowSymLinks
       AllowOverride None
       Require all granted
   </Directory>
   ```

10. **Save** the file (Ctrl+S)
11. **Restart Apache** in XAMPP Control Panel

---

## **🔥 STEP 3: Configure Windows Firewall**

### **3.1 Allow Apache Through Firewall**

1. Open **Windows Defender Firewall**

   - Press `Win + R`, type `firewall.cpl`, press Enter

2. Click **Advanced Settings** (on the left)

3. Click **Inbound Rules** → **New Rule**

4. Select **Port** → **Next**

5. Select **TCP**, enter port **80** → **Next**

6. Select **Allow the connection** → **Next**

7. Check all profiles (Domain, Private, Public) → **Next**

8. Name it: **"Apache HTTP Server"** → **Finish**

### **3.2 Allow MySQL Through Firewall (if needed)**

1. Repeat steps 3-8 above
2. Use port **3306** (MySQL default port)
3. Name it: **"MySQL Database"**

**Alternative Quick Method:**

```powershell
# Run as Administrator in PowerShell
New-NetFirewallRule -DisplayName "Apache HTTP Server" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow
New-NetFirewallRule -DisplayName "MySQL Database" -Direction Inbound -Protocol TCP -LocalPort 3306 -Action Allow
```

---

## **🌍 STEP 4: Test Server Access**

### **On the Server Computer:**

1. Open browser
2. Try accessing: `http://[YOUR_IP]/geotexx`
   - Example: `http://192.168.1.100/geotexx`
3. If it works, proceed to Step 5

### **If it doesn't work:**

- Check if Apache is running
- Check Windows Firewall settings
- Verify IP address is correct
- Try disabling firewall temporarily to test

---

## **📱 STEP 5: Share Access Information with Users**

### **Information to Provide:**

1. **Access URL:**

   ```
   http://[SERVER_IP]/geotexx
   ```

   Example: `http://192.168.1.100/geotexx`

2. **Login Credentials:**

   - Username: (their username)
   - Password: (their password)

3. **Network Requirements:**
   - Must be on the same network (Wi-Fi/LAN) as the server
   - Cannot access from outside the office network (unless VPN is set up)

---

## **💻 STEP 6: User Access Instructions**

### **For Users (Send this to them):**

1. **Ensure you're on the same network:**

   - Connect to the office Wi-Fi
   - Or connect via Ethernet cable

2. **Open your web browser:**

   - Chrome, Firefox, Edge, or Safari

3. **Enter the access URL:**

   ```
   http://[SERVER_IP]/geotexx
   ```

   (Replace `[SERVER_IP]` with the actual IP address provided)

4. **Login:**

   - Enter your username and password
   - Click "Login"

5. **If you can't access:**
   - Check your internet connection
   - Verify you're on the same network
   - Contact IT support

---

## **🔒 STEP 7: Security Considerations**

### **⚠️ Important Security Notes:**

1. **Current Setup is for Local Network Only:**

   - The system is accessible only within your local network
   - This is good for security but limits remote access

2. **For Remote Access (Outside Office):**

   - Set up a **VPN** (Virtual Private Network)
   - Or use **port forwarding** with a router (not recommended for production)
   - Or deploy to a **cloud server** (AWS, Azure, etc.)

3. **Recommended Security Measures:**
   - ✅ Use strong passwords
   - ✅ Enable HTTPS (SSL certificate) for production
   - ✅ Regularly update XAMPP and PHP
   - ✅ Restrict database access
   - ✅ Monitor access logs

---

## **🌐 STEP 8: Access from Different Networks (Advanced)**

### **Option 1: VPN Setup**

1. Set up a VPN server on your network
2. Users connect to VPN first
3. Then access the application using the server's local IP

### **Option 2: Port Forwarding (Not Recommended)**

1. Configure router to forward port 80 to server IP
2. Users access via: `http://[PUBLIC_IP]/geotexx`
3. **Warning:** This exposes your server to the internet

### **Option 3: Cloud Deployment**

1. Deploy to AWS, Azure, or similar
2. Get a domain name
3. Users access via: `https://yourdomain.com`

---

## **🐛 TROUBLESHOOTING**

### **Issue: "This site can't be reached"**

**Solutions:**

- ✅ Check if Apache is running on server
- ✅ Verify server IP address is correct
- ✅ Ensure both devices are on same network
- ✅ Check Windows Firewall settings
- ✅ Try accessing from server itself first

### **Issue: "Connection timed out"**

**Solutions:**

- ✅ Check firewall rules
- ✅ Verify Apache is listening on `0.0.0.0:80`
- ✅ Check if antivirus is blocking connections
- ✅ Try accessing from another device on same network

### **Issue: "Access Denied" or "403 Forbidden"**

**Solutions:**

- ✅ Check Apache `httpd.conf` - ensure `Require all granted`
- ✅ Verify file permissions in `htdocs/geotexx`
- ✅ Check `.htaccess` files (if any)

### **Issue: Database Connection Errors**

**Solutions:**

- ✅ MySQL must be running on server
- ✅ Database credentials must be correct
- ✅ Check `config/security_config.php` for database settings

---

## **📊 QUICK REFERENCE**

### **Server Configuration Checklist:**

- [ ] Apache configured to listen on `0.0.0.0:80`
- [ ] Apache `httpd.conf` has `Require all granted`
- [ ] Windows Firewall allows port 80
- [ ] Apache service is running
- [ ] MySQL service is running
- [ ] Server IP address is known and static (recommended)

### **User Access Checklist:**

- [ ] Users are on the same network
- [ ] Users have the correct access URL
- [ ] Users have login credentials
- [ ] Users can access the login page
- [ ] Users can log in successfully

---

## **📞 SUPPORT**

### **For Server Administrator:**

If users report access issues:

1. Check Apache error logs: `C:\xampp\apache\logs\error.log`
2. Check if services are running
3. Verify network connectivity
4. Test access from server first

### **For Users:**

If you cannot access the system:

1. Contact your IT administrator
2. Provide your IP address
3. Describe the error message you see

---

## **✅ TESTING CHECKLIST**

Before sharing with users, test:

- [ ] Server can access: `http://localhost/geotexx`
- [ ] Server can access: `http://[SERVER_IP]/geotexx`
- [ ] Another device on same network can access: `http://[SERVER_IP]/geotexx`
- [ ] Login works from remote device
- [ ] All pages load correctly
- [ ] Database connections work

---

**🎉 Once all steps are complete, users can access the system from their laptops!**

**Access URL Format:** `http://[SERVER_IP]/geotexx`

**Example:** `http://192.168.1.100/geotexx`
