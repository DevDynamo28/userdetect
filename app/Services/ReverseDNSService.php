<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ReverseDNSService
{
    /**
     * ISP hostname patterns for Indian ISPs.
     * Each pattern maps a regex to a capture group containing the city token.
     */
    private array $ispPatterns = [
        // GTPL: host.city.gtpl.net.in
        'gtpl' => '/\.([a-z]+)\.gtpl\.net\.in/i',

        // Hathway: host.city.hathway.com
        'hathway' => '/\.([a-z]+)\.hathway\.com/i',

        // Airtel: abts-city-*.airtelbroadband.in
        'airtel' => '/abts-([a-z]{3,})-.*\.airtelbroadband\.in/i',

        // Airtel mobile: city.airtel.in
        'airtel_mobile' => '/([a-z]+)\.airtel\.in/i',

        // BSNL: host.city.bsnl.in
        'bsnl' => '/\.([a-z]+)\.bsnl\.in/i',

        // BSNL NIC: city.nic.in
        'bsnl_nic' => '/([a-z]+)\.nic\.in/i',

        // Spectranet: host.city.spectranet.in
        'spectranet' => '/\.([a-z]+)\.spectranet\.in/i',

        // Railwire: host.city.railwire
        'railwire' => '/\.([a-z]+)\.railwire/i',

        // ACT Fibernet: city.actcorp.in
        'act' => '/([a-z]+)\.actcorp\.in/i',

        // Tikona: city.tikona.in
        'tikona' => '/([a-z]+)\.tikona\.in/i',

        // Excitel: city.excitel.com
        'excitel' => '/([a-z]+)\.excitel\.com/i',

        // Jio: city.jio.com
        'jio' => '/([a-z]+)\.jio\.com/i',

        // YOU Broadband: city.youbroadband.in
        'you' => '/([a-z]+)\.youbroadband\.in/i',

        // Alliance Broadband: city.alliance.net.in
        'alliance' => '/([a-z]+)\.alliance\.net\.in/i',

        // Generic pattern: city.isp/net/broadband
        'generic' => '/([a-z]{4,})\.(isp|net|broadband)/i',
    ];

    /**
     * City alias map: abbreviation/variant → canonical city name.
     */
    private array $cityAliases = [
        // Mumbai
        'mum' => 'Mumbai', 'mumbai' => 'Mumbai', 'bom' => 'Mumbai', 'bombay' => 'Mumbai',
        // Delhi
        'del' => 'Delhi', 'delhi' => 'Delhi', 'newdelhi' => 'Delhi', 'ndls' => 'Delhi',
        // Ahmedabad
        'ahm' => 'Ahmedabad', 'amd' => 'Ahmedabad', 'ahmedabad' => 'Ahmedabad', 'ahmadabad' => 'Ahmedabad',
        // Bangalore
        'blr' => 'Bangalore', 'bangalore' => 'Bangalore', 'bengaluru' => 'Bangalore', 'bang' => 'Bangalore',
        // Chennai
        'chn' => 'Chennai', 'chennai' => 'Chennai', 'madras' => 'Chennai', 'maa' => 'Chennai',
        // Kolkata
        'kol' => 'Kolkata', 'kolkata' => 'Kolkata', 'calcutta' => 'Kolkata', 'ccu' => 'Kolkata',
        // Hyderabad
        'hyd' => 'Hyderabad', 'hyderabad' => 'Hyderabad',
        // Pune
        'pune' => 'Pune', 'pun' => 'Pune', 'poona' => 'Pune',
        // Surat
        'surat' => 'Surat', 'srt' => 'Surat',
        // Jaipur
        'jaipur' => 'Jaipur', 'jai' => 'Jaipur', 'jpr' => 'Jaipur',
        // Lucknow
        'lucknow' => 'Lucknow', 'lko' => 'Lucknow', 'lkw' => 'Lucknow',
        // Kanpur
        'kanpur' => 'Kanpur', 'knp' => 'Kanpur',
        // Nagpur
        'nagpur' => 'Nagpur', 'nag' => 'Nagpur',
        // Indore
        'indore' => 'Indore', 'idr' => 'Indore',
        // Thane
        'thane' => 'Thane', 'thn' => 'Thane',
        // Bhopal
        'bhopal' => 'Bhopal', 'bpl' => 'Bhopal',
        // Vadodara
        'vadodara' => 'Vadodara', 'vad' => 'Vadodara', 'baroda' => 'Vadodara',
        // Rajkot
        'rajkot' => 'Rajkot', 'raj' => 'Rajkot',
        // Visakhapatnam
        'vizag' => 'Visakhapatnam', 'visakhapatnam' => 'Visakhapatnam', 'vtz' => 'Visakhapatnam',
        // Patna
        'patna' => 'Patna', 'pat' => 'Patna',
        // Coimbatore
        'coimbatore' => 'Coimbatore', 'cbe' => 'Coimbatore',
        // Agra
        'agra' => 'Agra',
        // Varanasi
        'varanasi' => 'Varanasi', 'benaras' => 'Varanasi',
        // Noida
        'noida' => 'Noida',
        // Gurgaon / Gurugram
        'gurgaon' => 'Gurugram', 'gurugram' => 'Gurugram', 'ggn' => 'Gurugram',
        // Chandigarh
        'chandigarh' => 'Chandigarh', 'chd' => 'Chandigarh',
        // Kochi
        'kochi' => 'Kochi', 'cochin' => 'Kochi',
        // Guwahati
        'guwahati' => 'Guwahati', 'gwh' => 'Guwahati',
        // Bhubaneswar
        'bhubaneswar' => 'Bhubaneswar', 'bbsr' => 'Bhubaneswar',
        // Dehradun
        'dehradun' => 'Dehradun', 'ddn' => 'Dehradun',
        // Ranchi
        'ranchi' => 'Ranchi', 'rnc' => 'Ranchi',
        // Gandhinagar
        'gandhinagar' => 'Gandhinagar', 'gnr' => 'Gandhinagar',
        // Thiruvananthapuram
        'trivandrum' => 'Thiruvananthapuram', 'thiruvananthapuram' => 'Thiruvananthapuram', 'trv' => 'Thiruvananthapuram',
        // Raipur
        'raipur' => 'Raipur', 'rpr' => 'Raipur',
        // Goa
        'goa' => 'Goa', 'panaji' => 'Goa',
        // Mysore / Mysuru
        'mysore' => 'Mysuru', 'mysuru' => 'Mysuru',
        // Mangalore
        'mangalore' => 'Mangalore', 'mangaluru' => 'Mangalore',
        // Jodhpur
        'jodhpur' => 'Jodhpur',
        // Udaipur
        'udaipur' => 'Udaipur',
        // Amritsar
        'amritsar' => 'Amritsar',
        // Ludhiana
        'ludhiana' => 'Ludhiana',
        // Nashik
        'nashik' => 'Nashik', 'nasik' => 'Nashik',
        // Aurangabad
        'aurangabad' => 'Aurangabad',
        // Jalandhar
        'jalandhar' => 'Jalandhar',
        // Gwalior
        'gwalior' => 'Gwalior',
        // Allahabad / Prayagraj
        'allahabad' => 'Prayagraj', 'prayagraj' => 'Prayagraj',
        // Meerut
        'meerut' => 'Meerut',
        // Tiruchirappalli
        'trichy' => 'Tiruchirappalli', 'tiruchirappalli' => 'Tiruchirappalli',
    ];

    /**
     * City to state mapping for confidence.
     */
    private array $cityStateMap = [
        'Mumbai' => 'Maharashtra', 'Pune' => 'Maharashtra', 'Nagpur' => 'Maharashtra',
        'Thane' => 'Maharashtra', 'Nashik' => 'Maharashtra', 'Aurangabad' => 'Maharashtra',
        'Delhi' => 'Delhi', 'Noida' => 'Uttar Pradesh', 'Gurugram' => 'Haryana',
        'Ahmedabad' => 'Gujarat', 'Surat' => 'Gujarat', 'Vadodara' => 'Gujarat',
        'Rajkot' => 'Gujarat', 'Gandhinagar' => 'Gujarat',
        'Bangalore' => 'Karnataka', 'Mysuru' => 'Karnataka', 'Mangalore' => 'Karnataka',
        'Chennai' => 'Tamil Nadu', 'Coimbatore' => 'Tamil Nadu', 'Tiruchirappalli' => 'Tamil Nadu',
        'Kolkata' => 'West Bengal',
        'Hyderabad' => 'Telangana', 'Visakhapatnam' => 'Andhra Pradesh',
        'Jaipur' => 'Rajasthan', 'Jodhpur' => 'Rajasthan', 'Udaipur' => 'Rajasthan',
        'Lucknow' => 'Uttar Pradesh', 'Kanpur' => 'Uttar Pradesh', 'Agra' => 'Uttar Pradesh',
        'Varanasi' => 'Uttar Pradesh', 'Meerut' => 'Uttar Pradesh', 'Prayagraj' => 'Uttar Pradesh',
        'Indore' => 'Madhya Pradesh', 'Bhopal' => 'Madhya Pradesh', 'Gwalior' => 'Madhya Pradesh',
        'Patna' => 'Bihar',
        'Chandigarh' => 'Chandigarh',
        'Kochi' => 'Kerala', 'Thiruvananthapuram' => 'Kerala',
        'Guwahati' => 'Assam',
        'Bhubaneswar' => 'Odisha',
        'Dehradun' => 'Uttarakhand',
        'Ranchi' => 'Jharkhand',
        'Raipur' => 'Chhattisgarh',
        'Goa' => 'Goa',
        'Amritsar' => 'Punjab', 'Ludhiana' => 'Punjab', 'Jalandhar' => 'Punjab',
    ];

    /**
     * Extract city from reverse DNS hostname.
     */
    public function extractCity(?string $hostname): ?array
    {
        if (empty($hostname) || $hostname === 'localhost') {
            return null;
        }

        $hostname = strtolower(trim($hostname));

        foreach ($this->ispPatterns as $isp => $pattern) {
            if (preg_match($pattern, $hostname, $matches)) {
                $token = $matches[1];
                $city = $this->fuzzyMatchCity($token);

                if ($city) {
                    $state = $this->cityStateMap[$city] ?? null;
                    $confidence = config('detection.methods.reverse_dns.confidence', 88);

                    Log::channel('detection')->info("Reverse DNS match: {$hostname} -> {$city} via {$isp} pattern");

                    return [
                        'city' => $city,
                        'state' => $state,
                        'confidence' => $confidence,
                        'method' => 'reverse_dns',
                        'isp_pattern' => $isp,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Fuzzy match a city token to a canonical city name.
     */
    public function fuzzyMatchCity(string $token): ?string
    {
        $normalized = strtolower(trim($token));

        // Direct lookup
        if (isset($this->cityAliases[$normalized])) {
            return $this->cityAliases[$normalized];
        }

        // Partial match: check if any alias starts with the token (min 3 chars)
        if (strlen($normalized) >= 3) {
            foreach ($this->cityAliases as $alias => $city) {
                if (str_starts_with($alias, $normalized)) {
                    return $city;
                }
            }
        }

        return null;
    }

    /**
     * Get the state for a given city.
     */
    public function getStateForCity(string $city): ?string
    {
        return $this->cityStateMap[$city] ?? null;
    }
}
