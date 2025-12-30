# 🚀 Quick Network Access Setup

## **Fast Setup (5 Minutes)**

### **Step 1: Find Your Server IP** (30 seconds)

Open **Command Prompt** and run:
```cmd
ipconfig
```

Look for **IPv4 Address** - Example: `192.168.1.100`

---

### **Step 2: Configure Apache** (2 minutes)

1. Open **XAMPP Control Panel**
2. Click **Config** → **httpd.conf**
3. Press `Ctrl+F` and search for: `Listen 80`
4. Change to: `Listen 0.0.0.0:80`
5. Search for: `Require local`
6. Change to: `Require all granted` (in the `<Directory "C:/xampp/htdocs">` section)
7. **Save** (Ctrl+S)
8. **Restart Apache** in XAMPP Control Panel

---

### **Step 3: Configure Firewall** (1 minute)

**Option A: Using Script (Easiest)**
- Right-click `setup_network_access.bat`
- Select **"Run as administrator"**
- Follow the prompts

**Option B: Manual Setup**
1. Press `Win + R`, type `firewall.cpl`, press Enter
2. Click **Advanced Settings** → **Inbound Rules** → **New Rule**
3. Select **Port** → **TCP** → Port **80** → **Allow** → **Finish**
4. Repeat for Port **3306** (MySQL)

---

### **Step 4: Test** (1 minute)

1. On the server, open browser
2. Go to: `http://[YOUR_IP]/geotexx`
   - Example: `http://192.168.1.100/geotexx`
3. If it works, you're done! ✅

---

### **Step 5: Share with Users**

Give users this URL:
```
http://[YOUR_IP]/geotexx
```

**Example:** `http://192.168.1.100/geotexx`

**Requirements:**
- Users must be on the same network (same Wi-Fi/LAN)
- They need their login credentials

---

## **Troubleshooting**

**Can't access from other computers?**
- ✅ Check Apache is running
- ✅ Verify firewall rules are added
- ✅ Ensure both devices are on same network
- ✅ Try accessing from server first: `http://[YOUR_IP]/geotexx`

**Still not working?**
- See full guide: `NETWORK_ACCESS_GUIDE.md`

---

**That's it! Users can now access the system from their laptops.** 🎉

