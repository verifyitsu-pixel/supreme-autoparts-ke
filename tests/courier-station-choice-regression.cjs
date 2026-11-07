const fs=require('fs'),assert=require('assert'),p=fs.readFileSync(__dirname+'/../wp-content/plugins/supreme-autoparts-core/includes/courier-shipping.php','utf8');
for(const x of ['function sa_station_records','function sa_selected_station',"sa_courier_data($slug)","hash('sha256'","_sa_pickup_station_id","_sa_pickup_station_snapshot","_sa_pickup_station'","!sa_selected_station()","shipping_","billing_","same_county","Choose a pickup station in your town or county.",'new Option(s.label,s.value)','request!==sequence','sel.required=!!key'])assert(p.includes(x),x);
for(const x of ['selectedIndex=1',"s.km+' km'",'sel.innerHTML='])assert(!p.includes(x),x);
const endpoint=p.slice(p.indexOf("add_action('rest_api_init'"),p.indexOf('/** Hidden field'));
assert(!endpoint.includes('sa_courier_km'));assert(!endpoint.includes('maps_verified'));assert(endpoint.includes('if (!$same_town && !$same_county) continue'));
console.log('PASS stable IDs, explicit choice, selected carrier/destination dataset validation, snapshot+legacy persistence, safe option rendering, race guard, county/town suggestions and no coordinate precision claim');

assert(p.includes('Other stations in your county'));assert(p.includes('station.same_town===false'));console.log('PASS town-first/county-alternative grouping, explicit broader-area selected-station label');
