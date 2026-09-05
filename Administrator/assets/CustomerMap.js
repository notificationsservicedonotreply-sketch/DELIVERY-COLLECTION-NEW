document.addEventListener("DOMContentLoaded", function () {
    const latElement = document.getElementById("lat");
    const lngElement = document.getElementById("lng");
    const storeElement = document.getElementById("StoreName");
    const mapElement = document.getElementById("map");

    if (!latElement || !lngElement || !storeElement || !mapElement) {
        console.warn("Map elements not found.", {
            lat: !!latElement,
            lng: !!lngElement,
            store: !!storeElement,
            map: !!mapElement
        });
        return;
    }

    const storeLat = parseFloat(latElement.value);
    const storeLng = parseFloat(lngElement.value);
    const storeName = storeElement.value;

    if (isNaN(storeLat) || isNaN(storeLng) || storeName.trim() === "") {
        console.warn("Invalid map coordinates.");
        return;
    }

    const map = L.map("map").setView([storeLat, storeLng], 16);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "&copy; MARS MAIN SYSTEM"
    }).addTo(map);

    const storeMarker = L.marker([storeLat, storeLng])
        .addTo(map)
        .bindPopup(storeName)
        .openPopup();

    // Fetch a real road route from OSRM and draw it
    async function drawRoadRoute(userLat, userLng) {
        const url =
            `https://router.project-osrm.org/route/v1/driving/` +
            `${userLng},${userLat};${storeLng},${storeLat}` +
            `?overview=full&geometries=geojson`;

        try {
            const response = await fetch(url);
            const data = await response.json();

            if (!data.routes || data.routes.length === 0) {
                console.warn("No route found.");
                return null;
            }

            const route = data.routes[0];
            const coords = route.geometry.coordinates.map(c => [c[1], c[0]]); // [lng,lat] -> [lat,lng]

            const routeLine = L.polyline(coords, {
                color: "blue",
                weight: 5,
                opacity: 0.7
            }).addTo(map);

            map.fitBounds(routeLine.getBounds(), { padding: [50, 50] });

            return {
                distanceKm: route.distance / 1000,   // meters -> km
                durationMin: route.duration / 60      // seconds -> minutes
            };
        } catch (err) {
            console.warn("Routing failed:", err);
            return null;
        }
    }

    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            async function (position) {
                const userLat = position.coords.latitude;
                const userLng = position.coords.longitude;

                L.marker([userLat, userLng])
                    .addTo(map)
                    .bindPopup("You are here");

                const routeInfo = await drawRoadRoute(userLat, userLng);
                const directionsUrl =
                    `https://www.google.com/maps/dir/?api=1&origin=${userLat},${userLng}&destination=${storeLat},${storeLng}`;

                if (routeInfo) {
                    storeMarker.bindPopup(
                        `<b>${storeName}</b><br>
                         Road distance: ${routeInfo.distanceKm.toFixed(2)} km<br>
                         Est. drive time: ${Math.round(routeInfo.durationMin)} min<br>
                         <a href="${directionsUrl}" target="_blank">Get directions</a>`
                    ).openPopup();
                } else {
                    storeMarker.bindPopup(
                        `<b>${storeName}</b><br>
                         <a href="${directionsUrl}" target="_blank">Get directions</a>`
                    ).openPopup();
                }
            },
            function (error) {
                console.warn("Geolocation failed or denied:", error.message);
            }
        );
    } else {
        console.warn("Geolocation not supported by this browser.");
    }
});