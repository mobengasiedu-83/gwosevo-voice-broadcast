# Campaign prices and separate carrier costs

Audio upload/conversion measures the verified PCM recording duration automatically; campaign selection uses that stored measurement. One server-side `CampaignPricing` calculation supplies the editor estimate and approved/launch snapshot:

- Billable minutes = ceiling(audio seconds / 60).
- Per-contact price = billable minutes × assigned client rate per minute.
- Estimated campaign amount = eligible contact count × per-contact price.

All MSISDNs in the same approved campaign use the same recording and frozen client tariff. There is no contact-level rate input. DND/suppressed recipients are excluded from the estimate and are rechecked before dispatch. The UI shows selected and eligible counts separately. Estimates cover one answered attempt per eligible contact; unanswered attempts are free and retries remain separate attempts.

For example, 75 seconds at GHS 0.20/minute is GHS 0.40/contact, or GHS 4,000 for 10,000 eligible contacts. This example does not change the client's configured rate plan.

The editor updates automatically on audio or audience changes and hides obsolete pricing while recalculating. Errors offer a retry rather than leaving the previous recording's price visible. Prices are calculated on the server, never trusted from browser input.

`campaign_versions.snapshot.broadcast.billing` and the immutable run snapshot contain audio ID, measured seconds/milliseconds, billed minutes, rate/minute, rate/contact, eligible/total/excluded contact counts, estimated amount, currency, minor-unit scale, rate-plan ID/version and policy version. The saved pricing panel reads that immutable snapshot. New global/client tariff assignments affect future campaign versions only; dispatch uses the approved `reservation_tariff`.

Carrier expenses are separate. Configure `TELECOM_RATE_PER_MINUTE=0.30`, `TELECOM_CURRENCY=GHS`, `TELECOM_MINOR_UNIT_SCALE=2` for the requested carrier rate. A call attempt freezes this carrier tariff before dialing. `call_telecom_usage` stores answer status, actual answered milliseconds, carrier billed milliseconds/minutes and calculated carrier cost. Carrier billing rounds actual answered time up to whole minutes, with a minimum one minute for answered calls, independently of the selected recording. The service never moves client wallet money.

CDRs and finance reports expose both client charges and carrier costs, including their separate currencies. Old calls with no saved carrier rate keep unknown costs blank; the current rate is not silently applied to historical traffic. Calculated carrier costs can be reconciled against provider invoices; they are identified as gateway-timed calculations, not provider-reported invoice costs.

Example: a 75-second recording at a client rate of GHS 0.20 costs the client GHS 0.40 when answered. If the call lasts 130 seconds and the carrier rate is GHS 0.30, carrier cost is GHS 0.90. That carrier expense does not create an extra wallet deduction.
