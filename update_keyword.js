#!/usr/bin/env nodejs

'use strict';

const json = require('fs').readFileSync('/dev/stdin', 'utf8');

const http = require('http');
const words = (json.match(/GET \/keyword\/[^,]+/g) || []).forEach((s) => {
	const keyword = s.replace('GET /keyword/', '').replace(')"', '').replace(']}', '');
	console.log(`update: ${keyword}`);

	http.get('http://localhost/update?keyword=' + encodeURIComponent(keyword), (res) => {
		if (res.statusCode !== 200) {
			console.log(res.statusCode);
			return;
		}
		let data = '';
		res.on('data', (chunk) => { data += chunk; });
		res.on('end', () => {
			console.log(JSON.parse(data));
		});
	}).on('error', (e) => {
		console.error(e.message);
	});
});
