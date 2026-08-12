import { useEffect, useState } from "react";
import { Spinner } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

interface Summary {
  total: number;
  enabled: number;
  premium: number;
  ads: boolean;
}

/** Compact tools status: enabled/total, premium count, AdSense on/off. */
export default function WidgetBody() {
  const [data, setData] = useState<Summary | null>(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    apiFetch("/tools/summary")
      .then((r) => (r.ok ? r.json() : Promise.reject()))
      .then(setData)
      .catch(() => setError(true));
  }, []);

  if (error) return <p className="text-sm opacity-70">—</p>;
  if (!data) return <p><Spinner /></p>;

  return (
    <div className="tools-widget space-y-1 text-sm">
      <p className="text-2xl font-semibold">{data.enabled}<span className="text-base opacity-60"> / {data.total}</span></p>
      <p className="opacity-70">sichtbare Tools</p>
      <p className="opacity-70">{data.premium} Premium · AdSense {data.ads ? "an" : "aus"}</p>
    </div>
  );
}
