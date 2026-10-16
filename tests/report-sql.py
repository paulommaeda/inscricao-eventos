"""Execute real report SELECTs in SQLite using synthetic WordPress rows.

The report joins, aggregates, WHERE conditions and pagination are the production SQL.
GREATEST and MySQL's LIKE escaping are registered explicitly. No payment network calls.
"""
import json
import re
import sqlite3
import sys

payload = json.load(sys.stdin)
db = sqlite3.connect(':memory:')
db.row_factory = sqlite3.Row
db.create_function('GREATEST', -1, max)

def mysql_like(pattern, value):
    expression, i = '', 0
    while i < len(pattern):
        c = pattern[i]
        if c == '\\' and i + 1 < len(pattern):
            i += 1
            expression += re.escape(pattern[i])
        else:
            expression += '.*' if c == '%' else '.' if c == '_' else re.escape(c)
        i += 1
    return int(re.fullmatch(expression, str(value or ''), re.I | re.S) is not None)

db.create_function('like', 2, mysql_like)
fields = {
    'events': 'id name timezone',
    'registrations': 'id event_id scope lot_id lot_name total max_installments method status admin_note updated_at deleted_at name email phone cpf_hash cpf_encrypted terms_snapshot terms_accepted_at created_at',
    'payments': 'id registration_id reference scope amount method cpf_snapshot diagnostic_json checkout_id checkout_url remote_id_hint webhook_at webhook_error state last_error operator_note created_at synced_at',
    'manuals': 'id registration_id payment_id remote_id amount method paid_on note request_key actor_id created_at reversed_at reversed_by reverse_note resolved_at resolved_state',
    'transactions': 'id payment_id scope remote_id remote_state credit paid_at synced_at cancel_status cancel_operation cancel_requested_at cancel_error cancel_actor',
}
numeric = {'id', 'event_id', 'registration_id', 'payment_id', 'total', 'amount', 'credit', 'lot_id', 'max_installments', 'cancel_actor', 'actor_id', 'reversed_by'}
for name, defaults in fields.items():
    items = payload['rows'].get('wp_ie_' + name, {})
    rows = list(items.values()) if isinstance(items, dict) else items
    columns = sorted(set(defaults.split()).union(*(row.keys() for row in rows)))
    types = ','.join('"' + column + '" ' + ('INTEGER' if column in numeric else 'TEXT') for column in columns)
    db.execute('CREATE TABLE wp_ie_' + name + '(' + types + ')')
    if rows:
        def value(row, column):
            item = row.get(column, 0 if column in numeric else '')
            return json.dumps(item) if isinstance(item, (list, dict)) else item
        db.executemany('INSERT INTO wp_ie_' + name + ' VALUES (' + ','.join('?' for _ in columns) + ')', [[value(row, column) for column in columns] for row in rows])
result = db.execute(payload['sql']).fetchall()
json.dump([dict(row) for row in result], sys.stdout, ensure_ascii=False)
