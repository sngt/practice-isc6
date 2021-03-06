sudo chmod 666 /var/log/nginx/access.log /var/log/nginx/error.log

echo -n '' | tee /var/log/nginx/access.log
echo -n '' | tee /var/log/nginx/error.log
echo -n '' | tee /home/isucon/.local/php/var/log/debug.log

WORK_DIR=$(cd $(dirname ${0}); pwd)

cd /home/isucon/isucon6q/
./isucon6q-bench | tee /tmp/bench_result

cat /var/log/nginx/access.log | awk '{
    gsub(/(\?[^\?]+)$/, "", $4);
    gsub(/\/keyword\/([^\/]+)$/, "/keyword/(.+)", $4);
    req = $3" "$4;
    res_time_sum[req] += $7;
    counts[req]++;
} END {
    for (req in res_time_sum) {
        print req"\t"res_time_sum[req]" / "counts[req]"\t= "(res_time_sum[req] / counts[req])"s";
    }
}' | sort -n -k 3

for keyword in `cat /tmp/bench_result | grep '{"pass":' | ${WORK_DIR}/extract.php`; do
    curl -X POST http://127.0.0.1:5000/htmlify --data-urlencode "keyword=${keyword}"
done

# sudo rm -rf /tmp/nginx/*; sudo systemctl restart nginx
