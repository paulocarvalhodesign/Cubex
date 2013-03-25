<?php
/**
 * @author  brooke.bryan
 */

namespace Cubex\KvStore\Cassandra\DataType;

class DoubleType extends CassandraType
{
  public function pack($value)
  {
    return pack("d", $value);
  }

  public function unpack($data)
  {
    return array_shift(unpack("d", $data));
  }
}
