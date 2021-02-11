import {useEffect, useState} from 'react'
import {Col, Collapse, Layout, Row, Space, Tabs, Typography} from 'antd'

import logo from './favalorologo.png'

import './App.css'

const {Panel} = Collapse
const {Header, Footer, Content} = Layout
const {TabPane} = Tabs
const {Text} = Typography

const FLOORS = [1, 3, 5, 6, 7, 8, 9]

function App() {
  const [patients, setPatients] = useState([])
  const [activeFloor, setActiveFloor] = useState(1)

  useEffect(() => {
    fetch(`http://localhost/labchart/labchart.php?piso=${activeFloor}`)
      .then(response => response.json())
      .then(setPatients)
  }, [activeFloor])

  const handleChange = floor => {
    const parsedFloor = floor.split('-')
    const floorNumber = Number(parsedFloor[1])

    setActiveFloor(floorNumber)
  }

  return (
    <Layout>
      <Header style={{display: 'flex', alignItems: 'center', height: '100px'}}>
        <img alt="Fundacion Favaloro" src={logo} style={{width: '180px'}} />
      </Header>
      <Content>
        <Tabs defaultActiveKey={1} centered onChange={handleChange}>
          {FLOORS.map((floor, index) => (
            <TabPane key={`floor-${floor}`} tab={`Piso ${floor}`}>
              <Collapse defaultActiveKey={[]}>
                {patients.map(({HC, Nombre = '', ...rest}) => (
                  <Panel
                    key={JSON.stringify(rest)}
                    header={`${rest['Cama']} - HC:${HC} - ${Nombre}`}
                  >
                    <Row gutter={[32]}>
                      {Object.keys(rest).map(title => {
                        if (title.toLocaleLowerCase() === 'cama') return null
                        if (title.toLowerCase() === 'orden') {
                          return (
                            <Col>
                              <Space direction="vertical">
                                <h1 style={{textTransform: 'capitalize'}}>
                                  {title}
                                </h1>
                                <Text>{rest[title]}</Text>
                              </Space>
                            </Col>
                          )
                        }

                        return (
                          <Col>
                            <Space direction="vertical">
                              <h1>{title}</h1>
                              {Object.keys(rest[title]).map(prop => (
                                <Text>
                                  {`${rest[title][prop].nombre_estudio}: ${rest[title][prop].resultado} ${rest[title][prop].unidades}`}
                                </Text>
                              ))}
                            </Space>
                          </Col>
                        )
                      })}
                    </Row>
                  </Panel>
                ))}
              </Collapse>
            </TabPane>
          ))}
        </Tabs>
      </Content>
      <Footer>Footer</Footer>
    </Layout>
  )
}

export default App
