# Deployment — Ghana, Kenya, South Africa data planes

Same architecture as [nigeria.md](nigeria.md): shared control plane anywhere,
country-pinned data plane, regional key management, residency guard mapped to
the local facilities. Only the country code and facility list change.

## Ghana (`data_residency=GH`)

Driven by the BoG outsourcing/cyber directives (packs `GH-BOG-CGD-2018`,
`GH-BOG-CYBER-2026`) and the Data Protection Act 2012 (`GH-DPA-2012`).

- Facilities: MainOne Appolonia City, Onix Data Centre (Accra), MTN/Vodafone
  business hosting.
- `RESIDENCY_DEFAULT_COUNTRY=GH`, all `RESIDENCY_DISK_*=GH`,
  `RESIDENCY_QUEUE_REDIS=GH`.

## Kenya (`data_residency=KE`)

Driven by the Data Protection Act 2019 (`KE-DPA-2019`) and CBK prudential
guidelines (`KE-CBK-PG`).

- Facilities: iXAfrica NBO1 (Nairobi), Africa Data Centres NBO, IconNet /
  Safaricom hosting.
- `RESIDENCY_DEFAULT_COUNTRY=KE`, all `RESIDENCY_DISK_*=KE`.

## South Africa (`data_residency=ZA`)

Driven by POPIA (`ZA-POPIA`) and the joint standards (`ZA-JOINT-STANDARD-2-2024`).

- Facilities: Teraco (Isando/Rondebosch), Africa Data Centres JHB, AWS
  af-south-1 / Azure South Africa North where cloud is acceptable to the
  customer's regulator.
- `RESIDENCY_DEFAULT_COUNTRY=ZA`, all `RESIDENCY_DISK_*=ZA`.

## Cross-plane rules (all countries)

1. A tenant's data plane never replicates to another country. Backups stay
   in-plane; the guard blocks a backup disk mapped elsewhere.
2. Group customers with subsidiaries in several countries run one data plane
   per country; the group dashboard consolidates through the application,
   not through database replication.
3. Any lawful export (regulator return, group audit request) goes through
   the cross-border transfer register with an NDPA/DPA/POPIA basis recorded —
   the register is what feeds the NDPC submission pack.
4. Keys are generated and stored in the same country as the data they
   protect. Losing a country's keys must never be recoverable from another
   country's plane.
