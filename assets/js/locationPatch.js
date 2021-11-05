$(function() {
    function coalesce(value, fallback)
    {
        return (value ? value : fallback);
    }

    function setIfNotNull(container, key, value)
    {
        if (value) {
            container[key] = value;
        }
        return;
    }

    $(document).on("ready", function() {
        var entity = (Math.random() > 0.5) ? "space" : "agent";
        var endpoint = MapasCulturais.baseURL + entity + "/locationPatch/";
        $.ajax({url: endpoint, type: "GET", success: function(r) {
            if (r.length < 1) {
                return;
            }
            var country = coalesce(MapasCulturais.pais, "br");
            query = r["query"] + ", " + country;
            fallback = r["fallback"] + ", " + country;
            token = r["token"];
            var parms = {
                fullAddress: query,
                country: country,
            };
            if (r["elements"]) {
                setIfNotNull(parms, "streetName", r["elements"]["street"]);
                setIfNotNull(parms, "number", r["elements"]["number"]);
                setIfNotNull(parms, "neighborhood", r["elements"]["neighborhood"]);
                setIfNotNull(parms, "city", r["elements"]["city"]);
                setIfNotNull(parms, "state", r["elements"]["state"]);
                setIfNotNull(parms, "postalCode", r["elements"]["postalcode"]);
                setIfNotNull(parms, "country", r["elements"]["country"]);
            }
            clearTimeout(window._geocoding_timeout);
            window._geocoding_timeout = setTimeout(function() {
                MapasCulturais.geocoder.geocode(parms, function(g) {
                    if (g.lat && g.lon) {
                        $.ajax({
                            url: endpoint,
                            type: "POST",
                            data: {
                                latitude: g.lat,
                                longitude: g.lon,
                                token: token
                            }
                        });
                    } else {
                        parms["fullAddress"] = fallback;
                        MapasCulturais.geocoder.geocode(parms, function (g) {
                            var data = (g.lat && g.lon) ? {
                                latitude: g.lat,
                                longitude: g.lon,
                                token: token
                            } : {token: token};
                            $.ajax({
                                url: endpoint,
                                type: "POST",
                                data: data
                            });
                            return;
                        });
                     }
                    return;
                });
                return;
            }, 1000);
            return;
        }});
        return;
    });
});
