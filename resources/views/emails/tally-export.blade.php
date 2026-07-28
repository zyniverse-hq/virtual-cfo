<x-mail::message>
# Tally XML Export

Hi,

Your scheduled Tally XML export for **{{ $companyName }}** is ready.

**Period:** {{ $periodDescription }}
**Transactions:** {{ $transactionCount }}

The XML file is attached to this email as a ZIP archive.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
