# 🖥️ Virtual Machine Deployment Guide - GEOCIL Automation System

## **Complete VM Setup & Auto-Start Configuration**

---

## **📋 PREREQUISITES**

- ✅ Virtual Machine (VMware, VirtualBox, Hyper-V, etc.)
- ✅ Windows Server or Windows 10/11 installed on VM
- ✅ XAMPP installed on VM
- ✅ Static IP address assigned to VM
- ✅ VM network configured (NAT or Bridged)

---

## **🌐 STEP 1: Configure VM Network Settings**

### **1.1 Network Adapter Configuration**

**For VMware:**
1. Right-click VM → **Settings** → **Network Adapter**
2. Select **Bridged** (recommended) or **NAT**
   - **Bridged**: VM gets its own IP on your network
   - **NAT**: VM shares host's IP (port forwarding needed)

**For VirtualBox:**
1. VM Settings → **Network**
2. Adapter 1 → **Bridged Adapter** or **NAT**

**For Hyper-V:**
1. VM Settings → **Network Adapter**
2. Select **External Virtual Switch** (bridged)

### **1.2 Assign Static IP to VM**

1. Open **Network Settings** on VM
2. Go to **Ethernet** → **Change adapter options**
3. Right-click your network adapter → **Properties**
4. Select **Internet Protocol Version 4 (TCP/IPv4)** → **Properties**
5. Select **Use the following IP address:**
   - **IP Address**: `192.168.1.100` (your assigned IP)
   - **Subnet Mask**: `255.255.255.0`
   - **Default Gateway**: `192.168.1.1` (your router IP)
   - **DNS**: `8.8.8.8` and `8.8.4.4` (Google DNS)
6. Click **OK**

**Verify IP:**
```cmd
ipconfig
```

---

## **🔧 STEP 2: Configure XAMPP for Network Access**

### **2.1 Edit Apache Configuration**

1. Open **XAMPP Control Panel**
2. Click **Config** → **httpd.conf**
3. Find and change:
   ```apache
   Listen 80
   ```
   To:
   ```apache
   Listen 0.0.0.0:80
   ```

4. Find:
   ```apache
   <Directory "C:/xampp/htdocs">
       Options Indexes FollowSymLinks
       AllowOverride None
       Require local
   </Directory>
   ```
5. Change `Require local` to:
   ```apache
   Require all granted
   ```

6. **Save** and **Restart Apache**

### **2.2 Configure Windows Firewall**

**Option A: Using Script (Easiest)**
- Run `setup_network_access.bat` as Administrator

**Option B: Manual Setup**
1. Windows Firewall → **Advanced Settings** → **Inbound Rules**
2. **New Rule** → **Port** → **TCP** → Port **80** → **Allow** → **Finish**
3. Repeat for Port **3306** (MySQL)

---

## **🚀 STEP 3: Configure Auto-Start Services (CRITICAL)**

### **3.1 Method 1: Install XAMPP as Windows Services (Recommended)**

1. Open **XAMPP Control Panel** (as Administrator)
2. Click **Config** button (top right)
3. Check **"Register service"** for **Apache**
4. Check **"Register service"** for **MySQL**
5. Click **Save**

**Result:**
- Apache and MySQL will start automatically on boot
- Services run in background (no need to open XAMPP Control Panel)

**Verify Services:**
```cmd
services.msc
```
Look for:
- **Apache2.4** (or similar)
- **MySQL** (or **mysql80**)

### **3.2 Method 2: Create Startup Script (Alternative)**

If Method 1 doesn't work, create a startup script:

**Create `start_xampp.bat`:**
```batch
@echo off
cd C:\xampp
start "" "C:\xampp\xampp-control.exe"
timeout /t 5 /nobreak >nul
"C:\xampp\apache_start.bat"
"C:\xampp\mysql_start.bat"
```

**Add to Startup:**
1. Press `Win + R`, type `shell:startup`, press Enter
2. Copy `start_xampp.bat` to this folder
3. Or create a shortcut to the batch file

### **3.3 Method 3: Task Scheduler (Most Reliable)**

1. Open **Task Scheduler** (`taskschd.msc`)
2. Click **Create Basic Task**
3. Name: **"Start XAMPP Services"**
4. Trigger: **When the computer starts**
5. Action: **Start a program**
6. Program: `C:\xampp\apache_start.bat`
7. Click **Finish**
8. Repeat for MySQL: `C:\xampp\mysql_start.bat`

**Or use PowerShell script:**
```powershell
# Run as Administrator
$action1 = New-ScheduledTaskAction -Execute "C:\xampp\apache\bin\httpd.exe" -Argument "-k start"
$trigger1 = New-ScheduledTaskTrigger -AtStartup
Register-ScheduledTask -TaskName "Start Apache" -Action $action1 -Trigger $trigger1 -RunLevel Highest

$action2 = New-ScheduledTaskAction -Execute "C:\xampp\mysql\bin\mysqld.exe"
$trigger2 = New-ScheduledTaskTrigger -AtStartup
Register-ScheduledTask -TaskName "Start MySQL" -Action $action2 -Trigger $trigger2 -RunLevel Highest
```

---

## **✅ STEP 4: Test Auto-Start**

### **4.1 Test Services Start on Boot**

1. **Restart the VM**
2. Wait for Windows to fully boot
3. **Don't open XAMPP Control Panel**
4. Open browser and test: `http://[VM_IP]/geotexx`
5. If it works, auto-start is configured! ✅

### **4.2 Verify Services are Running**

**Check Apache:**
```cmd
netstat -an | findstr :80
```
Should show: `0.0.0.0:80` or `[VM_IP]:80`

**Check MySQL:**
```cmd
netstat -an | findstr :3306
```
Should show: `0.0.0.0:3306`

**Or use Services:**
```cmd
services.msc
```
Verify **Apache** and **MySQL** services are **Running**

---

## **🔒 STEP 5: Configure VM Auto-Start (Optional)**

### **5.1 Auto-Start VM on Host Boot**

**For VMware:**
1. Edit VM Settings → **Options** → **Power**
2. Check **"Start virtual machine automatically"**
3. Select **"Start automatically with delay"** (recommended: 30 seconds)

**For VirtualBox:**
1. VM Settings → **General** → **Advanced**
2. Enable **"Auto-start"** (requires VirtualBox extension pack)

**For Hyper-V:**
```powershell
# Run as Administrator
Set-VM -Name "YourVMName" -AutomaticStartAction Start
```

---

## **📊 STEP 6: Monitoring & Maintenance**

### **6.1 Create Service Status Check Script**

**Create `check_services.bat`:**
```batch
@echo off
echo Checking XAMPP Services...
echo.

netstat -an | findstr :80 >nul
if %errorLevel% equ 0 (
    echo [OK] Apache is running on port 80
) else (
    echo [ERROR] Apache is NOT running!
)

netstat -an | findstr :3306 >nul
if %errorLevel% equ 0 (
    echo [OK] MySQL is running on port 3306
) else (
    echo [ERROR] MySQL is NOT running!
)

echo.
pause
```

### **6.2 Create Service Restart Script**

**Create `restart_xampp.bat`:**
```batch
@echo off
echo Restarting XAMPP Services...
echo.

net stop Apache2.4
net stop mysql80

timeout /t 3 /nobreak >nul

net start Apache2.4
net start mysql80

echo.
echo Services restarted!
pause
```

---

## **🌍 STEP 7: Network Access Configuration**

### **7.1 VM Network Access**

**From Other Computers:**
- Access URL: `http://[VM_IP]/geotexx`
- Example: `http://192.168.1.100/geotexx`

**Requirements:**
- VM and client computers on same network
- VM firewall allows port 80
- VM has static IP (recommended)

### **7.2 Port Forwarding (If Using NAT)**

If VM uses NAT networking:
1. Configure port forwarding on host
2. Forward Host Port 80 → VM Port 80
3. Users access via: `http://[HOST_IP]/geotexx`

---

## **🔄 STEP 8: Backup & Recovery**

### **8.1 Automated Database Backup**

**Create scheduled backup:**
1. Use existing `database/automated_backup.bat`
2. Schedule in Task Scheduler
3. Run daily at 2 AM

### **8.2 VM Snapshot (Recommended)**

**Before major changes:**
1. Take VM snapshot
2. If something breaks, restore snapshot
3. System returns to working state

---

## **🐛 TROUBLESHOOTING**

### **Issue: Services Don't Start on Boot**

**Solutions:**
- ✅ Verify services are installed (Method 1)
- ✅ Check Task Scheduler tasks are enabled
- ✅ Verify service startup type is "Automatic"
- ✅ Check Windows Event Viewer for errors

**Check Service Status:**
```cmd
sc query Apache2.4
sc query mysql80
```

**Set to Automatic:**
```cmd
sc config Apache2.4 start= auto
sc config mysql80 start= auto
```

### **Issue: Can't Access from Network After Reboot**

**Solutions:**
- ✅ Verify services are running: `services.msc`
- ✅ Check firewall rules are still active
- ✅ Verify VM IP address hasn't changed
- ✅ Test from VM itself first: `http://localhost/geotexx`

### **Issue: VM IP Changes After Reboot**

**Solutions:**
- ✅ Set static IP (Step 1.2)
- ✅ Configure DHCP reservation on router
- ✅ Or use VM's MAC address binding

### **Issue: XAMPP Services Stop Unexpectedly**

**Solutions:**
- ✅ Check Windows Event Viewer
- ✅ Review Apache error logs: `C:\xampp\apache\logs\error.log`
- ✅ Check MySQL error logs: `C:\xampp\mysql\data\*.err`
- ✅ Increase service recovery options:
  - Services → Apache → Properties → Recovery
  - Set to "Restart the service" on failure

---

## **✅ DEPLOYMENT CHECKLIST**

### **Pre-Deployment:**
- [ ] VM has static IP address
- [ ] XAMPP installed and working
- [ ] Application accessible on VM: `http://localhost/geotexx`
- [ ] Database configured and working

### **Network Configuration:**
- [ ] Apache configured to listen on `0.0.0.0:80`
- [ ] Apache `httpd.conf` has `Require all granted`
- [ ] Windows Firewall allows port 80
- [ ] Windows Firewall allows port 3306

### **Auto-Start Configuration:**
- [ ] Apache service installed and set to Automatic
- [ ] MySQL service installed and set to Automatic
- [ ] Tested reboot - services start automatically
- [ ] Application accessible after reboot without manual intervention

### **Testing:**
- [ ] VM rebooted - services auto-start ✅
- [ ] Application accessible from VM: `http://localhost/geotexx` ✅
- [ ] Application accessible from network: `http://[VM_IP]/geotexx` ✅
- [ ] Database connections work ✅
- [ ] Users can login from other computers ✅

---

## **📝 QUICK REFERENCE**

### **Service Management Commands:**

```cmd
# Start services
net start Apache2.4
net start mysql80

# Stop services
net stop Apache2.4
net stop mysql80

# Check service status
sc query Apache2.4
sc query mysql80

# Set to auto-start
sc config Apache2.4 start= auto
sc config mysql80 start= auto
```

### **Access URLs:**

- **From VM**: `http://localhost/geotexx`
- **From Network**: `http://[VM_IP]/geotexx`
- **Example**: `http://192.168.1.100/geotexx`

---

## **🎯 SUMMARY**

### **After Following This Guide:**

1. ✅ **VM has static IP** - Won't change after reboot
2. ✅ **XAMPP services auto-start** - No manual intervention needed
3. ✅ **Network access configured** - Users can access from their laptops
4. ✅ **Firewall configured** - Ports 80 and 3306 are open
5. ✅ **System survives reboots** - Everything starts automatically

### **What Happens on Reboot:**

1. VM boots up
2. Windows starts
3. Apache service starts automatically
4. MySQL service starts automatically
5. Application is accessible immediately
6. **No need to open XAMPP Control Panel!** ✅

---

**🚀 Your system is now production-ready and will survive reboots!**

