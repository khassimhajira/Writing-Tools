# 📖 Beginner's Guide: StealthWriter Proxy Hub

Welcome! This guide will help you set up your proxy hub and test it across all your devices.

---

## 🛠 Step 1: Getting your Premium Cookies
To "share" your account, our system needs your session cookies.
1.  Open **Chrome** and go to `https://stealthwriter.ai`.
2.  Log in to your **Premium Account**.
3.  Open your **Cookie Editor** extension.
4.  Click **"Export"** (usually copies to clipboard as a JSON or a string).
5.  Open `config.json` in this folder.
6.  Paste the string inside the `"cookies": "..."` section.

---

## 🚀 Step 2: Running the Server
Since your Node.js is at `D:\nodejs\node.exe`, do the following:
1.  Open **PowerShell** or **CMD**.
2.  Navigate to your folder: `cd D:\stealthwriter`
3.  Run the command:
    ```bash
    & "D:\nodejs\node.exe" server.js
    ```
4.  You should see a message: `STEALTHWRITER PROXY HUB RUNNING on http://localhost:3000`.

---

## 📱 Step 3: Testing on Multiple Devices
You want to see if it works on your phone or other laptops?

### Method A: Same Wi-Fi (No extra cost)
1.  Find your local IP address: Run `ipconfig` in CMD. Look for `IPv4 Address` (e.g., `192.168.1.10`).
2.  On your **Phone**, open the browser and go to: `http://192.168.1.10:3000`.
3.  The Dashboard will load! Click **Launch** to start the session.

### Method B: Testing from Anywhere (Using ngrok)
If you want to test from a different network:
1.  Download **ngrok** (it's free).
2.  Run: `ngrok http 3000`.
3.  It will give you a public link like `https://a1b2-c3d4.ngrok-free.app`.
4.  Send this link to any device in the world to test!

---

## 🛠 Configuration & Customization
To manage your server's settings, IPs, and secrets without touching the code, use the **`.env`** file in the root folder.

### Changing the Residential Proxy IP
If your proxy limit is exceeded or you want to use a different IP:
1.  Open the **`.env`** file.
2.  Update the `PROXY_URL` value:
    `PROXY_URL=http://username:password@ip:port`
3.  **Restart the server.**

### Production Deployment (e.g., Hostinger)
When pushing to a live server:
1.  Set the `NODE_ENV` to `production`.
2.  Update the `JWT_SECRET` to a unique random string.
3.  Ensure `ADMIN_USER`, `ADMIN_EMAIL`, and `ADMIN_PASSWORD` are set for the first-time setup.
4.  If your host uses a different port, update the `PORT` variable.

---

## ⚠️ Important Notes
- **Don't Logout**: If you manually log out of StealthWriter on your main browser, the cookies will die. Just close the tab instead.
- **Concurrent Use**: We have implemented the "Queue" and "Stealth" logic to ensure your account isn't flagged for having 400 people on it at once.

**Enjoy your new StealthWriter Hub!**
