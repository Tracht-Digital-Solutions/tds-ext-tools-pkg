import { SummaryMetric } from "@tracht-digital-solutions/tds-shared/components";

interface Summary {
  total: number;
  enabled: number;
  premium: number;
  ads: boolean;
}

/** Compact tools status: enabled/total, premium count, AdSense on/off. */
export default function WidgetBody() {
  return (
    <SummaryMetric<Summary>
      endpoint="/tools/summary"
      value={(d) => (
        <>
          {d.enabled}
          <span className="text-base opacity-60"> / {d.total}</span>
        </>
      )}
      caption={() => "sichtbare Tools"}
    >
      {(d) => (
        <p className="marginalia">
          {d.premium} Premium · AdSense {d.ads ? "an" : "aus"}
        </p>
      )}
    </SummaryMetric>
  );
}
