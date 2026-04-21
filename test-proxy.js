require('dotenv').config();
const axios = require('axios');
const dns = require('dns');
const { HttpsProxyAgent } = require('https-proxy-agent');

const target = 'stealthwriter.ai';
const targetUrl = 'https://' + target;

// Proxy Credentials from .env (testing the first one in the list)
const proxyUrls = (process.env.PROXY_LIST || '').split(',').map(u => u.trim());
const proxyUrl = proxyUrls[0] || 'http://hdwqzkwd:syll1k420ir7@31.59.20.176:6754';
const agent = new HttpsProxyAgent(proxyUrl);

console.log('==========================================');
console.log('   STEALTHWRITER PROXY DIAGNOSTIC TOOL    ');
console.log('==========================================\n');

// 1. DNS CHECK
dns.lookup(target, (err, address) => {
    if (err) {
        console.log('❌ DNS RESOLUTION FAILED:', err.message);
    } else {
        console.log('🌐 DNS RESOLUTION:', address);
        if (address === '216.150.1.1' || address === '127.0.0.1') {
            console.log('⚠️  WARNING: Your network is RESOLVING this site to a local/sinkhole IP.');
        }
    }

    console.log('\n--- ATTEMPTING HTTP CONNECTIVITY ---');
    
    // 2. HTTP CHECK
    const start = Date.now();
    axios.get(targetUrl, { 
        timeout: 8000,
        headers: { 'User-Agent': 'Mozilla/5.0' }
    })
    .then(res => {
        console.log('✅ SUCCESS: Target reached in ' + (Date.now() - start) + 'ms');
        console.log('   Status Code:', res.status);
    })
    .catch(err => {
        console.log('❌ CONNECTIVITY FAILED!');
        console.log('   Error Code:', err.code || 'UNKNOWN');
        console.log('   Message:', err.message);
        console.log('\n💡 SUGGESTION: Your network is blocking this domain. Try changing DNS or using a VPN.');
    });
});
