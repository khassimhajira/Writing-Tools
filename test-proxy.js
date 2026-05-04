const { HttpsProxyAgent } = require('https-proxy-agent');
const axios = require('axios');
require('dotenv').config();

async function testProxies() {
    const proxyUrls = (process.env.PROXY_LIST || '').split(',').map(u => u.trim()).filter(u => u);
    console.log(`Found ${proxyUrls.length} proxies to test.\n`);

    for (let i = 0; i < proxyUrls.length; i++) {
        const url = proxyUrls[i];
        console.log(`[${i+1}] Testing: ${url.split('@')[1] || url}`); // Mask credentials
        const agent = new HttpsProxyAgent(url);
        
        try {
            const start = Date.now();
            const res = await axios.get('https://api.ipify.org?format=json', { 
                httpsAgent: agent,
                timeout: 10000 
            });
            console.log(`   ✅ SUCCESS! Proxy IP: ${res.data.ip} (${Date.now() - start}ms)`);
        } catch (err) {
            console.error(`   ❌ FAILED: ${err.message}`);
        }
    }
}

testProxies();
