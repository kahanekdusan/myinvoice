-- Produkční vlastní šablony mohou zachovávat prefix odesílatele, ale uvnitř
-- stále obsahovat historické pevné označení faktury. Nahradíme pouze tento
-- fragment dynamickým typem dokladu a zbytek vlastního předmětu ponecháme.
UPDATE email_templates
SET subject = REPLACE(
    subject,
    'Faktura {{ invoice.varsymbol }}',
    '{{ document_type_label }} {{ invoice.varsymbol }}'
)
WHERE code = 'invoice_send'
  AND locale = 'cs'
  AND subject LIKE '%Faktura {{ invoice.varsymbol }}%';

UPDATE email_templates
SET subject = REPLACE(
    subject,
    'Invoice {{ invoice.varsymbol }}',
    '{{ document_type_label }} {{ invoice.varsymbol }}'
)
WHERE code = 'invoice_send'
  AND locale = 'en'
  AND subject LIKE '%Invoice {{ invoice.varsymbol }}%';
